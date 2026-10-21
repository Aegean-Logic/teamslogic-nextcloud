<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Shared vCard helpers for the Google and Graph contact converters. Contacts are written as
 * vCard 3.0, as Nextcloud's Contacts app does.
 */
final class VCards {
	public const PRODID = '-//Aegean Logic//TeamsLogic for Nextcloud//EN';
	/** Year Apple and Nextcloud use for a birthday without one. */
	public const NO_YEAR = '1604';

	public static function newCard(string $uid): VCard {
		$card = new VCard(['VERSION' => '3.0', 'PRODID' => self::PRODID, 'UID' => $uid]);
		return $card;
	}

	public static function parse(string $vcf): VCard {
		/** @var VCard $card */
		$card = Reader::read($vcf, Reader::OPTION_FORGIVING);
		return $card;
	}

	/** @return list<string> upper-case TYPE values of a property */
	public static function types(Property $prop): array {
		$out = [];
		foreach ($prop->parameters() as $param) {
			if (strtoupper($param->name) === 'TYPE') {
				foreach ($param->getParts() as $part) {
					foreach (explode(',', (string)$part) as $type) {
						$out[] = strtoupper(trim($type));
					}
				}
			}
		}
		if (isset($prop['PREF'])) {
			$out[] = 'PREF';
		}
		return array_values(array_unique(array_filter($out)));
	}

	public static function text(VCard $card, string $name): ?string {
		$value = isset($card->{$name}) ? trim((string)$card->{$name}) : '';
		return $value === '' ? null : $value;
	}

	/**
	 * N as [family, given, middle, prefix, suffix].
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
	 */
	public static function name(VCard $card): array {
		$parts = isset($card->N) ? $card->N->getParts() : [];
		$parts = array_map('strval', array_pad($parts, 5, ''));
		return [$parts[0], $parts[1], $parts[2], $parts[3], $parts[4]];
	}

	/**
	 * ADR as [pobox, extended, street, city, region, postcode, country] with its types.
	 *
	 * @return list<array{parts: list<string>, types: list<string>}>
	 */
	public static function addresses(VCard $card): array {
		$out = [];
		foreach ($card->select('ADR') as $adr) {
			$out[] = [
				'parts' => array_map('strval', array_pad($adr->getParts(), 7, '')),
				'types' => self::types($adr),
			];
		}
		return $out;
	}

	/** ORG as [company, department]. */
	public static function org(VCard $card): array {
		$parts = isset($card->ORG) ? array_map('strval', $card->ORG->getParts()) : [];
		return [$parts[0] ?? '', $parts[1] ?? ''];
	}

	/**
	 * BDAY as [year or null, month, day].
	 *
	 * @return array{0: ?int, 1: int, 2: int}|null
	 */
	public static function birthday(VCard $card): ?array {
		if (!isset($card->BDAY)) {
			return null;
		}
		$value = trim((string)$card->BDAY);
		$omitYear = isset($card->BDAY['X-APPLE-OMIT-YEAR']);
		if (preg_match('/^--(\d{2})-?(\d{2})/', $value, $m)) {
			return [null, (int)$m[1], (int)$m[2]];
		}
		if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $value, $m)) {
			$year = ($omitYear || $m[1] === self::NO_YEAR) ? null : (int)$m[1];
			return [$year, (int)$m[2], (int)$m[3]];
		}
		return null;
	}

	public static function setBirthday(VCard $card, ?int $year, int $month, int $day): void {
		if ($month < 1 || $day < 1) {
			return;
		}
		if ($year === null || $year <= 0) {
			$card->add('BDAY', sprintf('%s-%02d-%02d', self::NO_YEAR, $month, $day), ['X-APPLE-OMIT-YEAR' => self::NO_YEAR]);
			return;
		}
		$card->add('BDAY', sprintf('%04d-%02d-%02d', $year, $month, $day), ['VALUE' => 'DATE']);
	}

	/**
	 * An embedded photo: its bytes and MIME type, or null for none (or a link).
	 *
	 * @return array{data: string, mime: string}|null
	 */
	public static function photo(VCard $card): ?array {
		if (!isset($card->PHOTO)) {
			return null;
		}
		$photo = $card->PHOTO;
		$raw = (string)$photo->getValue();
		if (preg_match('#^data:([^;,]+)?(;base64)?,(.*)$#s', $raw, $m)) {
			$data = $m[2] !== '' ? base64_decode($m[3], true) : rawurldecode($m[3]);
		} elseif (isset($photo['ENCODING']) && in_array(strtoupper((string)$photo['ENCODING']), ['B', 'BASE64'], true)) {
			// Sabre has already decoded ENCODING=b values.
			$data = $raw;
		} else {
			return null;
		}
		if (!is_string($data) || $data === '') {
			return null;
		}
		return ['data' => $data, 'mime' => self::mime($data)];
	}

	public static function setPhoto(VCard $card, string $data): void {
		$type = match (self::mime($data)) {
			'image/png' => 'PNG',
			'image/gif' => 'GIF',
			default => 'JPEG',
		};
		$card->add('PHOTO', $data, ['ENCODING' => 'b', 'TYPE' => $type]);
	}

	public static function mime(string $data): string {
		return match (true) {
			str_starts_with($data, "\x89PNG") => 'image/png',
			str_starts_with($data, 'GIF8') => 'image/gif',
			default => 'image/jpeg',
		};
	}

	/** A display name: FN, else the parts of N, else the organisation, else the first email. */
	public static function displayName(VCard $card): string {
		$fn = self::text($card, 'FN');
		if ($fn !== null) {
			return $fn;
		}
		[$family, $given, $middle, $prefix, $suffix] = self::name($card);
		$name = trim(preg_replace('/\s+/', ' ', "$prefix $given $middle $family $suffix") ?? '');
		if ($name !== '') {
			return $name;
		}
		[$company] = self::org($card);
		if ($company !== '') {
			return $company;
		}
		return isset($card->EMAIL) ? (string)$card->EMAIL : '';
	}
}
