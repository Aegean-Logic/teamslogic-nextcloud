<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use Sabre\VObject\Component\VCard;

/**
 * vCard ⇄ Microsoft Graph (Outlook) contact.
 *
 * Outlook has fixed slots: three email addresses, a mobile, lists of business and home phones,
 * and one home, business and other address. What doesn't fit is left out on the way to Outlook.
 * The photo travels separately (/contacts/{id}/photo/$value).
 */
final class GraphContactConverter {
	/** @param array<string, mixed> $c */
	public function toVCard(array $c, string $uid, ?string $photo = null): VCard {
		$card = VCards::newCard($uid);
		$card->add('N', [
			(string)($c['surname'] ?? ''), (string)($c['givenName'] ?? ''), (string)($c['middleName'] ?? ''),
			(string)($c['title'] ?? ''), (string)($c['generation'] ?? ''),
		]);
		if (!empty($c['displayName'])) {
			$card->FN = (string)$c['displayName'];
		}
		if (!empty($c['nickName'])) {
			$card->add('NICKNAME', (string)$c['nickName']);
		}

		foreach ((array)($c['emailAddresses'] ?? []) as $i => $email) {
			if (!empty($email['address'])) {
				$card->add('EMAIL', (string)$email['address'], ['TYPE' => $i === 0 ? ['INTERNET', 'PREF'] : ['INTERNET']]);
			}
		}
		if (!empty($c['mobilePhone'])) {
			$card->add('TEL', (string)$c['mobilePhone'], ['TYPE' => ['CELL']]);
		}
		foreach ((array)($c['businessPhones'] ?? []) as $phone) {
			if ($phone !== '' && $phone !== null) {
				$card->add('TEL', (string)$phone, ['TYPE' => ['WORK', 'VOICE']]);
			}
		}
		foreach ((array)($c['homePhones'] ?? []) as $phone) {
			if ($phone !== '' && $phone !== null) {
				$card->add('TEL', (string)$phone, ['TYPE' => ['HOME', 'VOICE']]);
			}
		}

		foreach (['homeAddress' => 'HOME', 'businessAddress' => 'WORK', 'otherAddress' => null] as $key => $type) {
			$adr = (array)($c[$key] ?? []);
			if (array_filter($adr) === []) {
				continue;
			}
			$card->add('ADR', [
				'', '', (string)($adr['street'] ?? ''), (string)($adr['city'] ?? ''), (string)($adr['state'] ?? ''),
				(string)($adr['postalCode'] ?? ''), (string)($adr['countryOrRegion'] ?? ''),
			], $type !== null ? ['TYPE' => $type] : []);
		}

		if (!empty($c['companyName']) || !empty($c['department'])) {
			$card->add('ORG', [(string)($c['companyName'] ?? ''), (string)($c['department'] ?? '')]);
		}
		if (!empty($c['jobTitle'])) {
			$card->add('TITLE', (string)$c['jobTitle']);
		}
		if (!empty($c['birthday']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$c['birthday'], $m)) {
			$year = (int)$m[1];
			VCards::setBirthday($card, $year <= 1604 ? null : $year, (int)$m[2], (int)$m[3]);
		}
		if (!empty($c['businessHomePage'])) {
			$card->add('URL', (string)$c['businessHomePage']);
		}
		if (!empty($c['personalNotes'])) {
			$card->add('NOTE', (string)$c['personalNotes']);
		}
		foreach ((array)($c['categories'] ?? []) as $category) {
			$card->add('CATEGORIES', (string)$category);
		}
		if ($photo !== null && $photo !== '') {
			VCards::setPhoto($card, $photo);
		}

		if (!isset($card->FN)) {
			$card->FN = VCards::displayName($card);
		}
		return $card;
	}

	/**
	 * The Graph body for a vCard.
	 *
	 * @return array<string, mixed>
	 */
	public function toContact(VCard $card): array {
		[$family, $given, $middle, $prefix, $suffix] = VCards::name($card);
		$contact = [
			'givenName' => $given,
			'surname' => $family,
			'middleName' => $middle,
			'title' => $prefix,
			'generation' => $suffix,
			'displayName' => VCards::displayName($card),
			'nickName' => VCards::text($card, 'NICKNAME') ?? '',
			'emailAddresses' => [],
			'businessPhones' => [],
			'homePhones' => [],
			'mobilePhone' => '',
			'homeAddress' => new \stdClass(),
			'businessAddress' => new \stdClass(),
			'otherAddress' => new \stdClass(),
		];

		$emails = [];
		foreach ($card->select('EMAIL') as $email) {
			$row = ['address' => trim((string)$email), 'name' => $contact['displayName']];
			// The preferred address goes first: Outlook's "Email".
			if (in_array('PREF', VCards::types($email), true)) {
				array_unshift($emails, $row);
			} else {
				$emails[] = $row;
			}
		}
		$contact['emailAddresses'] = array_slice($emails, 0, 3);

		foreach ($card->select('TEL') as $tel) {
			$types = VCards::types($tel);
			$number = trim((string)$tel);
			if (in_array('FAX', $types, true) || in_array('PAGER', $types, true)) {
				continue;
			}
			if (in_array('CELL', $types, true) && $contact['mobilePhone'] === '') {
				$contact['mobilePhone'] = $number;
			} elseif (in_array('WORK', $types, true)) {
				$contact['businessPhones'][] = $number;
			} else {
				$contact['homePhones'][] = $number;
			}
		}

		foreach (VCards::addresses($card) as $adr) {
			[, $extended, $street, $city, $region, $postcode, $country] = $adr['parts'];
			$key = in_array('WORK', $adr['types'], true) ? 'businessAddress'
				: (in_array('HOME', $adr['types'], true) ? 'homeAddress' : 'otherAddress');
			if (!($contact[$key] instanceof \stdClass)) {
				continue;
			}
			$contact[$key] = [
				'street' => trim($street . ($extended !== '' ? "\n" . $extended : '')),
				'city' => $city, 'state' => $region, 'postalCode' => $postcode, 'countryOrRegion' => $country,
			];
		}

		[$company, $department] = VCards::org($card);
		$contact['companyName'] = $company;
		$contact['department'] = $department;
		$contact['jobTitle'] = VCards::text($card, 'TITLE') ?? '';
		$bday = VCards::birthday($card);
		// Outlook has no birthday without a year; it shows 1604 the way Apple does.
		$contact['birthday'] = $bday === null ? null : sprintf('%04d-%02d-%02dT11:59:00Z', $bday[0] ?? (int)VCards::NO_YEAR, $bday[1], $bday[2]);
		$contact['businessHomePage'] = VCards::text($card, 'URL') ?? '';
		$contact['personalNotes'] = VCards::text($card, 'NOTE') ?? '';
		$categories = [];
		foreach ($card->select('CATEGORIES') as $prop) {
			foreach ($prop->getParts() as $category) {
				$categories[] = (string)$category;
			}
		}
		$contact['categories'] = $categories;
		return $contact;
	}

	/**
	 * Photo bytes to upload to /contacts/{id}/photo/$value.
	 *
	 * @return array{data: string, mime: string}|null
	 */
	public function photoFor(VCard $card): ?array {
		return VCards::photo($card);
	}
}
