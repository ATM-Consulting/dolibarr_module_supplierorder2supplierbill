<?php

/**
 * Class SupplierOrder2SupplierBill
 */
class SupplierOrder2SupplierBill
{
	/**
	 * @param array $TCommandesFournisseurs
	 * @param int $dateFact
	 *
	 * @return int
	 */
	function generate_factures($TCommandesFournisseurs, $dateFact=0) {
		global $conf, $langs, $db, $user;

		// Inclusion des classes nécessaires
		dol_include_once('/fourn/class/fournisseur.commande.class.php');
		dol_include_once('/fourn/class/fournisseur.class.php');
		dol_include_once('/fourn/class/fournisseur.facture.class.php');
		dol_include_once('/core/modules/supplier_invoice/modules_facturefournisseur.php');

		// Utilisation du module sous-total si activé
		if($conf->subtotal->enabled) {
			dol_include_once('/subtotal/class/actions_subtotal.class.php');
			$langs->load("subtotal@subtotal");
			$sub = new ActionsSubtotal();
		}

		if(empty($dateFact))
			$dateFact = dol_now();

		$nbFacture = 0;
		$TFiles = array();
		// Pour chaque id fournisseur
		foreach($TCommandesFournisseurs as $id_fournisseur => $Tid_commande){
			$fournisseur = new Fournisseur($db);
			$fournisseur->fetch($id_fournisseur);

			$f = $this->facture_create($fournisseur, $dateFact, key($Tid_commande));
			if ($f<0) {
				return -1;
			}
			$nbFacture++;

			//Pour chaque id commande
			foreach($Tid_commande as $id_cmd => $val) {
				// Chargement de la commande
				$cmd = new CommandeFournisseur($db);
				$cmd->fetch($id_cmd);

				// Lien avec la facture only if link not already exists
				$f->fetchObjectLinked($id_cmd,'order_supplier','',$targettype='invoice_supplier');
				if (is_array($f->linkedObjectsIds)
						&& ((array_key_exists('invoice_supplier', $f->linkedObjectsIds)
								&& ! in_array($f->id, $f->linkedObjectsIds['invoice_supplier']))
						   || (! array_key_exists('invoice_supplier', $f->linkedObjectsIds)))) {

					$f->add_object_linked('order_supplier', $cmd->id);
				}

				// Ajout du titre
				$this->facture_add_title($f, $cmd, $sub);
				// Ajout des lignes
				$this->facture_add_line($f, $cmd);
				// Ajout du sous-total
				$this->facture_add_subtotal($f, $sub);
			}

			// Validation de la facture
			if($conf->global->SHIP2BILL_VALID_INVOICE) $f->validate($user, '', $conf->global->SHIP2BILL_WARHOUSE_TO_USE);

			// Génération du PDF
			if(!empty($conf->global->SHIP2BILL_GENERATE_INVOICE_PDF)) $TFiles[] = $this->facture_generate_pdf($f);
		}

		if($conf->global->SHIP2BILL_GENERATE_GLOBAL_PDF) $this->generate_global_pdf($TFiles);

		return $nbFacture;

	}

	/**
	 * @param Fournisseur $fournisseur
	 * @param int $dateFact
	 * @param int $id_commande
	 *
	 * @return FactureFournisseur
	 */
	function facture_create($fournisseur, $dateFact,$id_commande) {
		global $user, $db;

		$f = new FactureFournisseur($db);
		$f->socid = $fournisseur->id;
		$f->fetch_thirdparty();

		// Données obligatoires
		$f->date = $dateFact;
		$f->type = 0;
		$f->cond_reglement_id = (!empty($f->thirdparty->cond_reglement_id) ? $f->thirdparty->cond_reglement_id : 1);
		$f->mode_reglement_id = $f->thirdparty->mode_reglement_id;
		$f->modelpdf = 'crabe';
		$f->statut = 0;

		$f->origin = "order_supplier";
		$f->origin_id = $id_commande;

		$f->ref_supplier = 'A définir' ;

		$result = $f->create($user);
		if ($result<0) {
			$this->error=$f->error;
			return $result;
		} else {
			return $f;
		}
	}

	/**
	 * @param FactureFournisseur $f
	 * @param CommandeFournisseur $cmd
	 */
	function facture_add_line(&$f, &$cmd) {
		global $conf, $db;

		// Pour chaque produit de la commande, ajout d'une ligne de facture
		foreach($cmd->lines as $l){
			if($conf->global->SHIPMENT_GETS_ALL_ORDER_PRODUCTS && $l->qty == 0) continue;
			$orderline = new CommandeFournisseurLigne($db);
			$orderline->fetch($l->id);

			$f->origin = "order_supplier";
			$f->origin_id = $cmd->id;
			$f->origin_line_id = $l->id;
			$f->addline($l->desc, $l->subprice, $l->tva_tx,$l->localtax1_tx,$l->localtax2_tx, $l->qty, $l->fk_product, $l->remise_percent,'','',0, '', 'HT', 0, -1, false);
		}

		//Récupération des services de la commande si SHIP2BILL_GET_SERVICES_FROM_ORDER
		if($conf->global->SHIP2BILL_GET_SERVICES_FROM_ORDER && (float) DOL_VERSION >= 3.5){
			dol_include_once('/fourn/class/fournisseur.commande.class.php');

			$commande = new CommandeFournisseur($db);
			$commande->fetch($cmd->id);
			foreach($commande->lines as $line){

				//Prise en compte des services et des lignes libre uniquement
				if($line->fk_product_type == 1 || (empty($line->fk_product_type) && empty($line->fk_product))){

					$f->origin = "order_supplier";
					$f->origin_line_id = $line->id;
					$f->origin_id = $commande->id;
					// FIXME: addline takes 16 parameters not 17 in Dolibarr 3.6
					$f->addline(
							$line->desc,
							$line->price,
							$line->tva_tx,
							0,0,
							$line->qty,
							$line->fk_product,
							$line->remise_percent,
							$line->date_start,
							$line->date_end,
							0,0,
							$line->fk_remise_except,
							'HT',
							0,
							$line->rang,
							$line->special_code
					);
				}
			}
		}
	}

	/**
	 * @param FactureFournisseur $f
	 * @param CommandeFournisseur $cmd
	 * @param ActionsSubtotal $sub
	 */
	function facture_add_title (&$f, &$cmd, &$sub) {
		global $conf, $langs, $db;

		// Affichage des références cmdéditions en tant que titre
		if($conf->global->SHIP2BILL_ADD_SHIPMENT_AS_TITLES) {
			$title = '';
			$cmd->fetchObjectLinked('','order_supplier');

			// Récupération des infos de la commande pour le titre
			if (! empty($cmd->linkedObjectsIds['order_supplier'][0])) {
				$ord = new CommandeFournisseur($db);
				$ord->fetch($cmd->linkedObjectsIds['order_supplier'][0]);
				$title.= $langs->transnoentities('Order').' '.$ord->ref;
				if(!empty($ord->ref_client)) $title.= ' / '.$ord->ref_client;
				if(!empty($ord->date_commande)) $title.= ' ('.dol_print_date($ord->date_commande,'day').')';
			}

			// Ajout du titre
			if($conf->subtotal->enabled) {
				if(method_exists($sub, 'addSubTotalLine')) $sub->addSubTotalLine($f, $title, 1);
				else {
					if((float) DOL_VERSION <= 3.4) $f->addline($f->id, $title, 0,1,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
					// FIXME: addline takes 16 parameters not 18 in Dolibarr 3.6
					else $f->addline($title, 0,1,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
				}
			} else {
				if((float) DOL_VERSION <= 3.4) $f->addline($f->id, $title, 0, 1, 0);
				else $f->addline($title, 0, 1);
			}
		}
	}

	/**
	 * @param FactureFournisseur $f
	 * @param ActionsSubtotal $sub
	 */
	function facture_add_subtotal(&$f,&$sub) {
		global $conf, $langs;

		// Ajout d'un sous-total par commande
		if($conf->global->SHIP2BILL_ADD_SHIPMENT_SUBTOTAL) {
			if($conf->subtotal->enabled) {
				if(method_exists($sub, 'addSubTotalLine')) $sub->addSubTotalLine($f, $langs->transnoentities('SubTotal'), 99);
				else {
					if((float) DOL_VERSION <= 3.4) $f->addline($f->id, $langs->transnoentities('SubTotal'), 0,99,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
					// FIXME: addline takes 16 parameters not 18 in Dolibarr 3.6
					else $f->addline($langs->transnoentities('SubTotal'), 0,99,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
				}
			}
		}
	}

	/**
	 * @param FactureFournisseur $f
	 *
	 * @return string
	 */
	function facture_generate_pdf(&$f) {
		global $conf, $langs, $db;

		// Il faut recharger les lignes qui viennent juste d'être créées
		$f->fetch($f->id);

		$outputlangs = $langs;
		if ($conf->global->MAIN_MULTILANGS) {$newlang=$f->client->default_lang;}
		if (! empty($newlang)) {
			$outputlangs = new Translate("",$conf);
			$outputlangs->setDefaultLang($newlang);
		}
		$result=supplier_invoice_pdf_create($db, $f, $f->modelpdf, $outputlangs);

		if($result > 0) {
			$objectref = dol_sanitizeFileName($f->ref);
			$dir = $conf->facture->dir_output . "/" . $objectref;
			$file = $dir . "/" . $objectref . ".pdf";
			return $file;
		}

		return '';
	}

	/**
	 * @param array $TFiles
	 */
	function generate_global_pdf($TFiles) {
		global $langs, $conf;

        // Create empty PDF
        $pdf=pdf_getInstance();
        if (class_exists('TCPDF'))
        {
            /** @var TCPDF $pdf */
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
        }
        $pdf->SetFont(pdf_getPDFFont($langs));

        if (! empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION)) $pdf->SetCompression(false);

		// Add all others
		foreach($TFiles as $file)
		{
			// Charge un document PDF depuis un fichier.
			/** @var FPDI $pdf */
			$pagecount = $pdf->setSourceFile($file);
			for ($i = 1; $i <= $pagecount; $i++)
			{
				$tplidx = $pdf->importPage($i);
				$s = $pdf->getTemplatesize($tplidx);
				$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
				$pdf->useTemplate($tplidx);
			}
		}

		// Create output dir if not exists
		$diroutputpdf = $conf->ship2bill->multidir_output[$conf->entity];
		dol_mkdir($diroutputpdf);

		// Save merged file
		$filename=strtolower(dol_sanitizeFileName($langs->transnoentities("ShipmentBilled")));
		if ($pagecount)
		{
			$now=dol_now();
			$file=$diroutputpdf.'/'.$filename.'_'.dol_print_date($now,'dayhourlog').'.pdf';
			$pdf->Output($file,'F');
			if (! empty($conf->global->MAIN_UMASK))
			@chmod($file, octdec($conf->global->MAIN_UMASK));
		}
		else
		{
			setEventMessage($langs->trans('NoPDFAvailableForChecked'),'errors');
		}
	}

	/**
	 * @param DoliDB $db
	 *
	 * @return string
	 */
	function getNextValue($db){
		dol_include_once('core/lib/functions2.lib.php');

		global $conf;

		$ref = get_next_value($db, $conf->global->MASQUE_REF_FOURN, 'facture_fourn', 'ref_supplier');

		return $ref;
	}
}
