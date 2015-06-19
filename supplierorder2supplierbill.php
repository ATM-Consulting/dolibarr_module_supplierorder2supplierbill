<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2010 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2010 Regis Houssin        <regis.houssin@capnetworks.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *      \file       htdocs/expedition/liste.php
 *      \ingroup    expedition
 *      \brief      Page to list all commands
 */
set_time_limit(180);

require 'config.php';
dol_include_once('/fourn/class/fournisseur.commande.class.php');
dol_include_once('/supplierorder2supplierbill/class/supplierorder2supplierbill.class.php');
dol_include_once('/core/class/html.formfile.class.php');
dol_include_once('/core/class/html.form.class.php');

global $bc, $conf, $db, $langs, $user;

$langs->load("deliveries");
$langs->load("orders");
$langs->load('companies');
$langs->load("supplierorder2supplierbill@supplierorder2supplierbill");

// Security check
if ($user->societe_id) $socid=$user->societe_id;
$result = restrictedArea($user, 'fournisseur', null, null, 'commande');

$hookmanager->initHooks(array('invoicecard'));

$action = GETPOST('action','alpha');
$search_ref_cmd = GETPOST("search_ref_cmd");
$search_societe = GETPOST("search_societe");

$page = GETPOST('page','int');
$diroutputpdf=$conf->ship2bill->multidir_output[$conf->entity];

if ($page == -1) { $page = 0; }
$offset = $conf->liste_limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
$limit = $conf->liste_limit;
if (! $sortfield) $sortfield="c.ref";
if (! $sortorder) $sortorder="DESC";
$limit = $conf->liste_limit;

if(isset($_REQUEST['subCreateBill'])){
	$TCommandeFournisseur = $_REQUEST['TCommandeFournisseur'];
	$dateFact = GETPOST('dtfact');
	if(empty($dateFact)) {
		$dateFact = dol_now();
	} else {
		$dateFact = dol_mktime(0, 0, 0, GETPOST('dtfactmonth'), GETPOST('dtfactday'), GETPOST('dtfactyear'));
	}
	
	if(empty($TCommandeFournisseur)) {
		setEventMessage('Aucune commande sélectionnée.', 'warnings');
	} else {
		$supporder2suppbill = new SupplierOrder2SupplierBill();
		$nbFacture = $supporder2suppbill->generate_factures($TCommandeFournisseur, $dateFact);
	
		setEventMessage($nbFacture . ' facture(s) créée(s).');
		//header("Location: ".dol_buildpath('/compta/facture/list.php',2));
		//exit;
	}
}

// Remove file
if ($action == 'remove_file')
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	$langs->load("other");
	$upload_dir = $diroutputpdf;
	$file = $upload_dir . '/' . GETPOST('file');
	$ret=dol_delete_file($file,0,0,0,'');
	if ($ret) setEventMessage($langs->trans("FileWasRemoved", GETPOST('urlfile')));
	else setEventMessage($langs->trans("ErrorFailToDeleteFile", GETPOST('urlfile')), 'errors');
	$action='';
}

// Do we click on purge search criteria ?
if (GETPOST("button_removefilter_x"))
{
    $search_ref_cmd='';
    $search_societe='';
}


/*
 * View
 */
 
$companystatic = new Societe($db);
$command = new CommandeFournisseur($db);

$helpurl='EN:Module_commands|FR:Module_Exp&eacute;ditions|ES:M&oacute;dulo_Expediciones';
llxHeader('', 'Commandes fournisseurs à facturer',$helpurl);
?>
<script type="text/javascript">
$(document).ready(function() {
	$("#checkall").click(function() {
		$(".checkforgen").attr('checked', true);
	});
	$("#checknone").click(function() {
		$(".checkforgen").attr('checked', false);
	});
});
</script>
<?php
$sql = "SELECT c.rowid, c.ref, c.fk_statut, s.nom as socname, s.rowid as socid";
$sql.= " FROM " . MAIN_DB_PREFIX . "commande_fournisseur as c";
$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = c.fk_soc";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."element_element as ee ON c.rowid = ee.fk_source AND ee.sourcetype = 'order_supplier'";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn as f ON f.rowid = ee.fk_target AND ee.targettype = 'invoice_supplier'";

if($conf->clinomadic->enabled){
	$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."commande_fournisseur_extrafields as cfe ON cfe.fk_object = c.rowid";
}

$sql.= " WHERE c.entity = ".$conf->entity;
$sql.= " AND c.fk_statut  = 5"; //reçu complètement
$sql.= " AND f.rowid IS NULL";

if($conf->clinomadic->enabled){
	$sql.= " AND (cfe.commande_traite != 1 OR cfe.commande_traite IS NULL)";
}


if ($socid)
	$sql.= " AND c.fk_soc = ".$socid;

if ($search_ref_cmd) $sql .= natural_search('c.ref', $search_ref_cmd);
if ($search_societe) $sql .= natural_search('s.nom', $search_societe);

$sql.= ' ORDER BY c.ref';
$sql.= $db->plimit($limit + 1, $offset);

$resql=$db->query($sql);

if ($resql)
{
	$num = $db->num_rows($resql);

	$command = new CommandeFournisseur($db);

	$param="&amp;socid=$socid";
	if ($search_ref_cmd) $param.= "&amp;search_ref_cmd=" . $search_ref_cmd;
	if ($search_societe) $param.= "&amp;search_societe=" . $search_societe;

	print_barre_liste('Commandes fournisseurs à facturer', $page, "supplierorder2supplierbill.php",$param, $sortfield, $sortorder,'',$num);
	
	print '<form name="formAfficheListe" method="POST" action="supplierorder2supplierbill.php">';

	$i = 0;
	print '<table class="noborder" width="100%">';

	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans("Ref"),"supplierorder2supplierbill.php","e.ref","",$param,'',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Company"),"supplierorder2supplierbill.php","s.nom", "", $param,'align="left"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Status"),"supplierorder2supplierbill.php","e.fk_statut","",$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre('Commandes à facturer',"supplierorder2supplierbill.php","","",$param, 'align="center"',$sortfield,$sortorder);
	print "</tr>\n";
	
	// Lignes des champs de filtre
	print '<tr class="liste_titre">';
	print '<td class="liste_titre">';
	print '<input class="flat" size="10" type="text" name="search_ref_exp" value="'.$search_ref_cmd.'">';
    print '</td>';
	print '<td class="liste_titre" align="left">';
	print '<input class="flat" type="text" size="10" name="search_societe" value="'.dol_escape_htmltag($search_societe).'">';
	print '</td>';
	print '<td class="liste_titre" align="right">';
	print '<input type="image" class="liste_titre" name="button_search" src="'.img_picto($langs->trans("Search"),'search.png','','',1).'" value="'.dol_escape_htmltag($langs->trans("Search")).'" title="'.dol_escape_htmltag($langs->trans("Search")).'">';
	print '<input type="image" class="liste_titre" name="button_removefilter" src="'.img_picto($langs->trans("Search"),'searchclear.png','','',1).'" value="'.dol_escape_htmltag($langs->trans("RemoveFilter")).'" title="'.dol_escape_htmltag($langs->trans("RemoveFilter")).'">';
	print '</td>';
	print '<td class="liste_titre" align="center">';
	print '<a href="#" id="checkall">'.$langs->trans("All").'</a> / <a href="#" id="checknone">'.$langs->trans("None").'</a>';
	print '</td>';
	
	print "</tr>\n";
	
	$var=True;

	while ($i < min($num,$limit))
	{
		$objp = $db->fetch_object($resql);
		$checkbox = 'TCommandeFournisseur['.$objp->socid.']['.$objp->rowid.']';

		$var=!$var;
		print "<tr ".$bc[$var].">";
		print "<td>";
		$command->id = $objp->rowid;
		$command->ref = $objp->ref;
		print $command->getNomUrl(1);
		print "</td>\n";
		// Third party
		print '<td>';
		$companystatic->id = $objp->socid;
		$companystatic->ref = $objp->socname;
		$companystatic->nom = $objp->socname;
		print $companystatic->getNomUrl(1);
		print '</td>';
		
		print '<td align="right">' . $command->LibStatut($objp->fk_statut, 5) . '</td>';
		
		// Sélection expé à facturer
		print '<td align="center">';
		print '<input type="checkbox" checked="checked" name="' . $checkbox . '" class="checkforgen" />';
		print "</td>\n";
		
		print "</tr>\n";

		$i++;
	}

	print "</table>";
	if($num > 0 && $user->rights->fournisseur->facture->creer) {
		$f = new Form($db);
		print '<br><div style="text-align: right;">';
		print $langs->trans('Date').' : ';
		$f->select_date('', 'dtfact');
		print '<input class="butAction" type="submit" name="subCreateBill" value="'.$langs->trans('CreateInvoiceButton').'" />';
		print '</div>';
	}
	print '</form>';

	if($conf->global->SHIP2BILL_GENERATE_GLOBAL_PDF) {
		print '<br><br>';
		// We disable multilang because we concat already existing pdf.
		$formfile = new FormFile($db);
		$formfile->show_documents('ship2bill','',$diroutputpdf,$urlsource,false,true,'',1,1,0,48,1,$param,$langs->trans("GlobalGeneratedFiles"));
	}
	
	$db->free($resql);
}
else
{
	dol_print_error($db);
}

$db->close();

llxFooter();
?>
