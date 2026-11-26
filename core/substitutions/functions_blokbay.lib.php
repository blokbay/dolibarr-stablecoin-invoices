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
 * \file    core/substitutions/functions_blokbay.lib.php
 * \ingroup blokbay
 * \brief   Substitution keys for email templates, PDF notes and ODT templates:
 *          __BLOKBAY_PAY_URL__ (the payment link) and __BLOKBAY_PAY_TEXT_AND_URL__ ("Pay in USDC or USDT: <link>").
 */

require_once dol_buildpath('/blokbay/lib/blokbay.lib.php', 0);

/**
 * Complete the substitution array with the Blokbay payment link of the invoice (empty when there is no open link).
 *
 * @param array<string,string> $substitutionarray Substitution array
 * @param Translate            $outputlangs       Output language
 * @param CommonObject|null    $object            Object
 * @param array|null           $parameters        Parameters
 * @return void
 */
function blokbay_completesubstitutionarray(&$substitutionarray, $outputlangs, $object, $parameters)
{
	global $db;
	$url = '';
	if (is_object($object) && ($object->element ?? '') === 'facture' && !empty($object->id)) {
		$url = blokbay_open_url($db, (int) $object->id);
	}
	$substitutionarray['__BLOKBAY_PAY_URL__'] = $url;
	$substitutionarray['__BLOKBAY_PAY_TEXT_AND_URL__'] = $url !== '' ? blokbay_pay_text($outputlangs, $url, false) : '';
}
