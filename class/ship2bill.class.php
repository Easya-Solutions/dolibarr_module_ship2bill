<?php

class Ship2Bill {
	
	function generate_factures($TExpedition) {
		global $conf, $langs, $db, $user;
		
		// Inclusion des classes nécessaires
		dol_include_once('/commande/class/commande.class.php');
		dol_include_once('/compta/facture/class/facture.class.php');
		dol_include_once('/core/modules/facture/modules_facture.php');
		
		// Utilisation du module livraison
		if($conf->livraison_bon->enabled) {
			dol_include_once('/livraison/class/livraison.class.php');
		}
		// Utilisation du module sous-total si activé
		if($conf->subtotal->enabled) {
			dol_include_once('/subtotal/class/actions_subtotal.class.php');
			$langs->load("subtotal@subtotal");
			$sub = new ActionsSubtotal();
		}
		
		$nbFacture = 0;
		// Pour chaque id client
		foreach($TExpedition as $id_client => $Tid_exp){
			$f = $this->facture_create($id_client);
			$nbFacture++;
			
			//Pour chaque id expédition
			foreach($Tid_exp as $id_exp => $val) {
				// Chargement de l'expédition
				$exp = new Expedition($db);
				$exp->fetch($id_exp);
				
				// Lien avec la facture
				$f->add_object_linked('shipping', $exp->id);
				// Ajout du titre
				$this->facture_add_title($f, $exp, $sub);
				// Ajout des lignes
				$this->facture_add_line($f, $exp);
				// Ajout du sous-total
				$this->facture_add_subtotal($f, $sub);
				
				// Clôture de l'expédition
				if($conf->global->SHIP2BILL_CLOSE_SHIPMENT) $exp->set_billed();
			}
				
			// Validation de la facture
			if($conf->global->SHIP2BILL_VALID_INVOICE) $f->validate($user, '', $conf->global->SHIP2BILL_WARHOUSE_TO_USE);
			
			// Génération du PDF
			if($conf->global->SHIP2BILL_GENERATE_INVOICE_PDF) $this->facture_generate_pdf($f);
		}

		return $nbFacture;
	}

	function facture_create($id_client) {
		global $user, $db;
		
		$f = new Facture($db);
		$f->socid = $id_client;
		$f->fetch_thirdparty();
		
		// Données obligatoires
		$f->date = dol_now();
		$f->type = 0;
		$f->cond_reglement_id = (!empty($f->thirdparty->cond_reglement_id) ? $f->thirdparty->cond_reglement_id : 1);
		$f->mode_reglement_id = $f->thirdparty->mode_reglement_id;
		$f->modelpdf = 'crabe';
		$f->statut = 0;
		$f->create($user);
		
		return $f;
	}
	
	function facture_add_line(&$f, &$exp) {
		global $conf, $db;
		
		// Pour chaque produit de l'expédition, ajout d'une ligne de facture
		foreach($exp->lines as $l){
			if($conf->global->SHIPMENT_GETS_ALL_ORDER_PRODUCTS && $l->qty == 0) continue;
			$orderline = new OrderLine($db);
			$orderline->fetch($l->fk_origin_line);
			if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $l->description, $l->subprice, $l->qty, $l->tva_tx,$l->localtax1tx,$l->localtax2tx,$l->fk_product, $l->remise_percent,'','',0,0,'','HT',0,0,-1,0,'',0,0,$orderline->fk_fournprice,$orderline->pa_ht);
			else $f->addline($l->description, $l->subprice, $l->qty, $l->tva_tx,$l->localtax1tx,$l->localtax2tx,$l->fk_product, $l->remise_percent,'','',0,0,'','HT',0,0,-1,0,'',0,0,$orderline->fk_fournprice,$orderline->pa_ht);
		}
	}
	
	function facture_add_title (&$f, &$exp, &$sub) {
		global $conf, $langs, $db;
		
		// Affichage des références expéditions en tant que titre
		if($conf->global->SHIP2BILL_ADD_SHIPMENT_AS_TITLES) {
			$title = '';
			$exp->fetchObjectLinked('','commande');
			
			// Récupération des infos de la commande pour le titre
			if (! empty($exp->linkedObjectsIds['commande'][0])) {
				$ord = new Commande($db);
				$ord->fetch($exp->linkedObjectsIds['commande'][0]);
				$title.= $langs->trans('Order').' '.$ord->ref;
				if(!empty($ord->ref_client)) $title.= ' / '.$ord->ref_client;
				if(!empty($ord->date_commande)) $title.= ' ('.dol_print_date($ord->date_commande,'day').')';
			}
			
			$title2 = $langs->trans('Shipment').' '.$exp->ref;
			if(!empty($exp->date_delivery)) $title2.= ' ('.dol_print_date($exp->date_delivery,'day').')';
			if($conf->livraison_bon->enabled) {
				$exp->fetchObjectLinked('','','','delivery');
				
				// Récupération des infos du BL pour le titre, sinon de l'expédition
				if (! empty($exp->linkedObjectsIds['delivery'][0])) {
					$liv = new Livraison($db);
					$liv->fetch($exp->linkedObjectsIds['delivery'][0]);
					$title2 = $langs->trans('Delivery').' '.$liv->ref;
					if(!empty($liv->date_delivery)) $title2.= ' ('.dol_print_date($liv->date_delivery,'day').')';
				}
			}
			
			$title.= ' - '.$title2;
			
			// Ajout du titre
			if($conf->subtotal->enabled) {
				if(method_exists($sub, 'addSubTotalLine')) $sub->addSubTotalLine($f, $title, 1);
				else {
					if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $title, 0,1,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
					else $f->addline($title, 0,1,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
				}
			} else {
				if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $title, 0, 1, 0);
				else $f->addline($title, 0, 1);
			}
		}
	}

	function facture_add_subtotal(&$f,&$sub) {
		global $conf, $langs;
		
		// Ajout d'un sous-total par expédition
		if($conf->global->SHIP2BILL_ADD_SHIPMENT_SUBTOTAL) {
			if($conf->subtotal->enabled) {
				if(method_exists($sub, 'addSubTotalLine')) $sub->addSubTotalLine($f, $langs->trans('SubTotal'), 99);
				else {
					if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $langs->trans('SubTotal'), 0,99,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
					else $f->addline($langs->trans('SubTotal'), 0,99,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
				}
			}
		}
	}
	
	function facture_generate_pdf(&$f) {
		global $conf, $langs, $db;
		
		// Il faut recharger les lignes qui viennent juste d'être créées
		$f->fetch_lines();
		
		$outputlangs = $langs;
		if ($conf->global->MAIN_MULTILANGS) {$newlang=$object->client->default_lang;}
		if (! empty($newlang)) {
			$outputlangs = new Translate("",$conf);
			$outputlangs->setDefaultLang($newlang);
		}
		$result=facture_pdf_create($db, $f, $f->modelpdf, $outputlangs);
	}
}