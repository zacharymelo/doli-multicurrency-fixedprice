<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modFixedprice.class.php
 * \ingroup fixedprice
 * \brief   Description and activation file for the Fixed Multicurrency Price module
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for the Fixed Multicurrency Price module.
 *
 * Allows setting fixed per-currency selling prices on products that override
 * the automatic exchange-rate conversion provided by the multicurrency module.
 */
class modFixedprice extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		$this->numero = 510001;

		$this->family = 'products';
		$this->module_position = '90';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Fixed multicurrency selling prices per product, overriding automatic exchange-rate conversion";
		$this->version = '1.1.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'multicurrency';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'hooks' => array(
				'data' => array(
					'productpricecard',
					'invoicecard',
					'ordercard',
					'propalcard',
				),
				'entity' => '0',
			),
		);

		$this->dirs = array();

		$this->config_page_url = array("setup.php@fixedprice");

		$this->hidden = false;
		$this->depends = array('modProduct');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("fixedprice@fixedprice");
		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(16, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array(
			array('FIXEDPRICE_DIVERGENCE_THRESHOLD', 'chaine', '10', 'Default divergence threshold percentage for fixed price warnings', 0, 'current', 1),
			array('FIXEDPRICE_WARN_ON_APPLY', 'chaine', '1', 'Show per-line notification when a fixed price overrides auto-conversion', 0, 'current', 1),
		);

		// Boxes / Widgets
		$this->boxes = array(
			0 => array(
				'file' => 'box_fixedprice_divergence.php@fixedprice',
				'enabledbydefaulton' => 'Home',
			),
		);

		// Permissions — simple read/write pair
		$this->rights = array();
		$this->rights_class = 'fixedprice';
		$r = 0;

		$this->rights[$r][0] = 510010;
		$this->rights[$r][1] = 'Read fixed multicurrency prices';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 510011;
		$this->rights[$r][1] = 'Create/modify fixed multicurrency prices';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 510012;
		$this->rights[$r][1] = 'Delete fixed multicurrency prices';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'delete';
		$this->rights[$r][5] = '';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left',
			'titre' => 'FixedPriceList',
			'mainmenu' => 'products',
			'leftmenu' => 'fixedprice_list',
			'url' => '/fixedprice/fixedprice_list.php',
			'langs' => 'fixedprice@fixedprice',
			'position' => 300,
			'perms' => '($user->hasRight("produit", "lire") || $user->hasRight("service", "lire"))',
			'enabled' => 'isModEnabled("fixedprice")',
			'target' => '',
			'user' => 2,
		);
		$r++;
	}

	/**
	 * Function called when module is enabled.
	 * The init function adds constants, boxes, permissions and menus
	 * (defined in constructor) into Dolibarr database.
	 * It also creates data directories.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/fixedprice/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
