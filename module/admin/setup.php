<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup fixedprice
 * \brief   Admin setup page for the Fixed Multicurrency Price module
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Access control
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "fixedprice@fixedprice"));

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if ($action == 'update') {
	$threshold = GETPOST('FIXEDPRICE_DIVERGENCE_THRESHOLD', 'alpha');
	$warn = GETPOST('FIXEDPRICE_WARN_ON_APPLY', 'alpha');

	$res = dolibarr_set_const($db, 'FIXEDPRICE_DIVERGENCE_THRESHOLD', $threshold, 'chaine', 0, '', $conf->entity);
	if ($res > 0) {
		$res = dolibarr_set_const($db, 'FIXEDPRICE_WARN_ON_APPLY', $warn, 'chaine', 0, '', $conf->entity);
	}

	if ($res > 0) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

/*
 * View
 */

$page_name = "FixedpriceSetup";
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';

// Header
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

// Divergence threshold
print '<tr class="oddeven">';
print '<td>'.$langs->trans("FixedpriceDivergenceThreshold");
print ' '.$form->textwithpicto('', $langs->trans("FixedpriceDivergenceThresholdHelp"));
print '</td>';
print '<td>';
print '<input type="text" name="FIXEDPRICE_DIVERGENCE_THRESHOLD" value="'.getDolGlobalString('FIXEDPRICE_DIVERGENCE_THRESHOLD', '10').'" size="5"> %';
print '</td>';
print '</tr>';

// Warn on apply
print '<tr class="oddeven">';
print '<td>'.$langs->trans("FixedpriceWarnOnApply");
print ' '.$form->textwithpicto('', $langs->trans("FixedpriceWarnOnApplyHelp"));
print '</td>';
print '<td>';
print $form->selectyesno('FIXEDPRICE_WARN_ON_APPLY', getDolGlobalString('FIXEDPRICE_WARN_ON_APPLY', '1'), 1);
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

llxFooter();
$db->close();
