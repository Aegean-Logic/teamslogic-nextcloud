<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use Sabre\VObject\Component\VCard;

/**
 * vCard ⇄ Google People API person.
 *
 * Google keeps photos apart (people.updateContactPhoto), so a photo goes one way as a link to
 * Google's copy, and the other way as bytes the caller uploads (photoFor()).
 */
final class GooglePersonConverter {
	/** The fields toPerson() fills: People's updatePersonFields / personFields. */
	public const UPDATE_FIELDS = 'names,nicknames,emailAddresses,phoneNumbers,addresses,organizations,birthdays,urls,biographies';
	public const READ_FIELDS = self::UPDATE_FIELDS . ',photos,metadata';

	private const PHONE_IN = [
		'mobile' => ['CELL'], 'home' => ['HOME', 'VOICE'], 'work' => ['WORK', 'VOICE'],
		'homeFax' => ['HOME', 'FAX'], 'workFax' => ['WORK', 'FAX'], 'otherFax' => ['FAX'],
		'pager' => ['PAGER'], 'main' => ['VOICE', 'PREF'], 'workMobile' => ['WORK', 'CELL'],
	];

	/** @param array<string, mixed> $person */
	public function toVCard(array $person, string $uid): VCard {
		$card = VCards::newCard($uid);

		$name = (array)($person['names'][0] ?? []);
		$card->add('N', [
			(string)($name['familyName'] ?? ''), (string)($name['givenName'] ?? ''), (string)($name['middleName'] ?? ''),
			(string)($name['honorificPrefix'] ?? ''), (string)($name['honorificSuffix'] ?? ''),
		]);
		foreach ((array)($person['nicknames'] ?? []) as $nick) {
			if (!empty($nick['value'])) {
				$card->add('NICKNAME', (string)$nick['value']);
			}
		}

		foreach ((array)($person['emailAddresses'] ?? []) as $email) {
			if (empty($email['value'])) {
				continue;
			}
			$types = ['INTERNET'];
			$type = strtolower((string)($email['type'] ?? 'other'));
			if (in_array($type, ['home', 'work'], true)) {
				$types[] = strtoupper($type);
			}
			if (!empty($email['metadata']['primary'])) {
				$types[] = 'PREF';
			}
			$card->add('EMAIL', (string)$email['value'], ['TYPE' => $types]);
		}

		foreach ((array)($person['phoneNumbers'] ?? []) as $phone) {
			if (empty($phone['value'])) {
				continue;
			}
			$types = self::PHONE_IN[(string)($phone['type'] ?? '')] ?? ['VOICE'];
			$card->add('TEL', (string)$phone['value'], ['TYPE' => $types]);
		}

		foreach ((array)($person['addresses'] ?? []) as $adr) {
			$type = strtolower((string)($adr['type'] ?? 'other'));
			$params = in_array($type, ['home', 'work'], true) ? ['TYPE' => strtoupper($type)] : [];
			$card->add('ADR', [
				(string)($adr['poBox'] ?? ''), (string)($adr['extendedAddress'] ?? ''), (string)($adr['streetAddress'] ?? ''),
				(string)($adr['city'] ?? ''), (string)($adr['region'] ?? ''), (string)($adr['postalCode'] ?? ''),
				(string)($adr['country'] ?? $adr['countryCode'] ?? ''),
			], $params);
		}

		$org = (array)($person['organizations'][0] ?? []);
		if (!empty($org['name']) || !empty($org['department'])) {
			$card->add('ORG', [(string)($org['name'] ?? ''), (string)($org['department'] ?? '')]);
		}
		if (!empty($org['title'])) {
			$card->add('TITLE', (string)$org['title']);
		}

		$date = (array)($person['birthdays'][0]['date'] ?? []);
		if (!empty($date['month']) && !empty($date['day'])) {
			VCards::setBirthday($card, isset($date['year']) ? (int)$date['year'] : null, (int)$date['month'], (int)$date['day']);
		}

		foreach ((array)($person['urls'] ?? []) as $url) {
			if (!empty($url['value'])) {
				$card->add('URL', (string)$url['value']);
			}
		}
		$note = (string)($person['biographies'][0]['value'] ?? '');
		if ($note !== '') {
			$card->add('NOTE', $note);
		}

		foreach ((array)($person['photos'] ?? []) as $photo) {
			// Google's placeholder letter isn't a photo.
			if (!empty($photo['url']) && empty($photo['default'])) {
				$card->add('PHOTO', (string)$photo['url'], ['VALUE' => 'URI']);
				break;
			}
		}

		$card->FN = VCards::displayName($card);
		return $card;
	}

	/**
	 * The People body for a vCard (for createContact, or updateContact with UPDATE_FIELDS).
	 *
	 * @return array<string, mixed>
	 */
	public function toPerson(VCard $card): array {
		[$family, $given, $middle, $prefix, $suffix] = VCards::name($card);
		$person = [
			'names' => [array_filter([
				'givenName' => $given, 'familyName' => $family, 'middleName' => $middle,
				'honorificPrefix' => $prefix, 'honorificSuffix' => $suffix,
			], static fn ($v) => $v !== '')],
			'nicknames' => [],
			'emailAddresses' => [],
			'phoneNumbers' => [],
			'addresses' => [],
			'organizations' => [],
			'birthdays' => [],
			'urls' => [],
			'biographies' => [],
		];
		if ($person['names'][0] === [] && ($fn = VCards::text($card, 'FN')) !== null) {
			$person['names'][0] = ['unstructuredName' => $fn];
		}

		foreach ($card->select('NICKNAME') as $nick) {
			$person['nicknames'][] = ['value' => (string)$nick];
		}
		foreach ($card->select('EMAIL') as $email) {
			$types = VCards::types($email);
			$row = ['value' => trim((string)$email), 'type' => $this->homeWork($types)];
			if (in_array('PREF', $types, true)) {
				$row['metadata'] = ['primary' => true];
			}
			$person['emailAddresses'][] = $row;
		}
		foreach ($card->select('TEL') as $tel) {
			$person['phoneNumbers'][] = ['value' => trim((string)$tel), 'type' => $this->phoneType(VCards::types($tel))];
		}
		foreach (VCards::addresses($card) as $adr) {
			[$pobox, $extended, $street, $city, $region, $postcode, $country] = $adr['parts'];
			$person['addresses'][] = array_filter([
				'poBox' => $pobox, 'extendedAddress' => $extended, 'streetAddress' => $street, 'city' => $city,
				'region' => $region, 'postalCode' => $postcode, 'country' => $country,
				'type' => $this->homeWork($adr['types']),
			], static fn ($v) => $v !== '');
		}
		[$company, $department] = VCards::org($card);
		$title = VCards::text($card, 'TITLE') ?? '';
		if ($company !== '' || $department !== '' || $title !== '') {
			$person['organizations'][] = array_filter(
				['name' => $company, 'department' => $department, 'title' => $title],
				static fn ($v) => $v !== '',
			);
		}
		if (($bday = VCards::birthday($card)) !== null) {
			[$year, $month, $day] = $bday;
			$person['birthdays'][] = ['date' => array_filter(['year' => $year, 'month' => $month, 'day' => $day])];
		}
		foreach ($card->select('URL') as $url) {
			$person['urls'][] = ['value' => (string)$url];
		}
		if (($note = VCards::text($card, 'NOTE')) !== null) {
			$person['biographies'][] = ['value' => $note, 'contentType' => 'TEXT_PLAIN'];
		}
		return $person;
	}

	/**
	 * Photo bytes to upload with updateContactPhoto: an embedded photo only (a link is Google's own).
	 *
	 * @return array{data: string, mime: string}|null
	 */
	public function photoFor(VCard $card): ?array {
		return VCards::photo($card);
	}

	private function homeWork(array $types): string {
		return in_array('WORK', $types, true) ? 'work' : (in_array('HOME', $types, true) ? 'home' : 'other');
	}

	private function phoneType(array $types): string {
		$has = static fn (string $t) => in_array($t, $types, true);
		return match (true) {
			$has('FAX') && $has('WORK') => 'workFax',
			$has('FAX') && $has('HOME') => 'homeFax',
			$has('FAX') => 'otherFax',
			$has('CELL') && $has('WORK') => 'workMobile',
			$has('CELL') => 'mobile',
			$has('PAGER') => 'pager',
			$has('WORK') => 'work',
			$has('HOME') => 'home',
			$has('PREF') => 'main',
			default => 'other',
		};
	}
}
