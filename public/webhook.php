<?php
/* Copyright (C) 2026 Blokbay <mail@blokbay.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    public/webhook.php
 * \ingroup blokbay
 * \brief   Signed Blokbay webhook (invoice.paid, invoice.voided, ...). The signature is checked, then the invoice is read again
 *          from the API and only the status read from the API changes the Dolibarr invoice.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
if (!defined('USESUFFIXINLOG')) {
	define('USESUFFIXINLOG', '_blokbay');
}
$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : 1);
if ($entity > 0) {
	define('DOLENTITY', $entity);
}

$res = 0;
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
	$res = @include __DIR__.'/../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
	$res = @include __DIR__.'/../../../main.inc.php';
}
if (!$res) {
	http_response_code(500);
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../lib/blokbay.lib.php';

/**
 * Plain text answer.
 *
 * @param string $msg    Body
 * @param int    $status HTTP status
 * @return void
 */
function blokbay_answer($msg, $status = 200)
{
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	print $msg;
	exit;
}

if (!isModEnabled('blokbay')) {
	blokbay_answer('module disabled', 404);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	blokbay_answer('method not allowed', 405);
}
$raw = (string) file_get_contents('php://input');
$header = (string) ($_SERVER['HTTP_BLOKBAY_SIGNATURE'] ?? '');
if (!blokbay_verify_signature($raw, $header, trim(getDolGlobalString('BLOKBAY_WEBHOOK_SECRET')))) {
	dol_syslog('Blokbay: webhook with an invalid signature', LOG_WARNING);
	blokbay_answer('bad signature', 400);
}
$event = json_decode($raw, true);
if (!is_array($event) || !is_string($event['type'] ?? null)) {
	blokbay_answer('bad payload', 400);
}
if ($event['type'] === 'ping') {
	blokbay_answer('pong');
}
if (strpos($event['type'], 'invoice.') !== 0) {
	blokbay_answer('ignored');
}
$invId = (string) ($event['data']['id'] ?? '');
if (!preg_match('/^inv_[a-z0-9]{6,40}$/', $invId) || !blokbay_get_row_by_inv($db, $invId)) {
	blokbay_answer('unknown invoice');
}

// Never trust the body: read the invoice again from the API.
$fresh = blokbay_request('GET', '/api/v1/invoices/'.rawurlencode($invId));
if ($fresh['status'] !== 200 || ($fresh['data']['id'] ?? '') !== $invId) {
	dol_syslog('Blokbay: unable to read the invoice '.$invId.' ('.$fresh['status'].' '.$fresh['error'].')', LOG_WARNING);
	blokbay_answer('retry', 503); // Blokbay delivers the event again later
}

// User for the actions: the one chosen in the settings, otherwise the first active administrator.
$user = new User($db);
$uid = getDolGlobalInt('BLOKBAY_USER_ACCOUNT_FOR_ACTIONS');
if (!$uid) {
	$r = $db->query('SELECT rowid FROM '.$db->prefix().'user WHERE admin = 1 AND statut = 1 AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid LIMIT 1');
	if ($r && ($o = $db->fetch_object($r))) {
		$uid = (int) $o->rowid;
	}
}
if ($uid <= 0 || $user->fetch($uid) <= 0) {
	blokbay_answer('no user for the actions', 503);
}
$user->loadRights();
$langs->loadLangs(array('main', 'bills', 'banks', 'blokbay@blokbay'));

$result = blokbay_apply_invoice($db, $fresh['data'], $user, $langs);
blokbay_answer($result, $result === 'retry' ? 503 : 200);
