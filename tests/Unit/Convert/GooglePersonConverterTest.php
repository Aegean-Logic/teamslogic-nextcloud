<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Convert;

use OCA\TeamsLogic\Convert\GooglePersonConverter;
use OCA\TeamsLogic\Convert\VCards;
use OCA\TeamsLogic\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

class GooglePersonConverterTest extends TestCase {
	private GooglePersonConverter $converter;

	protected function setUp(): void {
		$this->converter = new GooglePersonConverter();
	}

	public function testPersonToVCard(): void {
		$card = $this->converter->toVCard(Fixtures::json('google/person.json'), 'google-uid-1');

		$this->assertSame('3.0', (string)$card->VERSION);
		$this->assertSame('google-uid-1', (string)$card->UID);
		$this->assertSame(['Public', 'Jane', 'Quinn', 'Dr', 'PhD'], $card->N->getParts());
		$this->assertSame('Dr Jane Quinn Public PhD', (string)$card->FN);
		$this->assertSame('JQ', (string)$card->NICKNAME);

		$emails = $card->select('EMAIL');
		$this->assertCount(2, $emails);
		$this->assertSame('jane@example.com', (string)$emails[0]);
		$this->assertSame(['INTERNET', 'HOME', 'PREF'], VCards::types($emails[0]));
		$this->assertSame(['INTERNET', 'WORK'], VCards::types($emails[1]));

		$tels = $card->select('TEL');
		$this->assertSame(['+44 7700 900123', '+44 20 7946 0123', '+44 20 7946 0999'], array_map('strval', $tels));
		$this->assertSame(['CELL'], VCards::types($tels[0]));
		$this->assertSame(['WORK', 'FAX'], VCards::types($tels[2]));

		$addresses = VCards::addresses($card);
		$this->assertSame(['', 'Flat 2', '1 High Street', 'London', '', 'SW1A 1AA', 'UK'], $addresses[0]['parts']);
		$this->assertSame(['HOME'], $addresses[0]['types']);
		$this->assertSame(['WORK'], $addresses[1]['types']);

		$this->assertSame(['Contoso Ltd', 'Research'], $card->ORG->getParts());
		$this->assertSame('Head of Research', (string)$card->TITLE);
		// No year: Nextcloud's convention.
		$this->assertSame('1604-05-17', (string)$card->BDAY);
		$this->assertSame('1604', (string)$card->BDAY['X-APPLE-OMIT-YEAR']);
		$this->assertSame([null, 5, 17], VCards::birthday($card));
		$this->assertSame('https://jane.example.com', (string)$card->URL);
		$this->assertSame('Met at the 2025 conference.', (string)$card->NOTE);
		$this->assertSame('https://lh3.googleusercontent.com/contacts/AB12cdEF=s100', (string)$card->PHOTO);
		$this->assertNull($this->converter->photoFor($card), 'a linked photo is not uploaded back');
	}

	public function testPersonRoundTrip(): void {
		$person = Fixtures::json('google/person.json');
		$body = $this->converter->toPerson($this->converter->toVCard($person, 'x'));

		$this->assertSame([['givenName' => 'Jane', 'familyName' => 'Public', 'middleName' => 'Quinn', 'honorificPrefix' => 'Dr', 'honorificSuffix' => 'PhD']], $body['names']);
		$this->assertSame([
			['value' => 'jane@example.com', 'type' => 'home', 'metadata' => ['primary' => true]],
			['value' => 'j.public@contoso.com', 'type' => 'work'],
		], $body['emailAddresses']);
		$this->assertSame(['mobile', 'work', 'workFax'], array_column($body['phoneNumbers'], 'type'));
		$this->assertSame(['extendedAddress' => 'Flat 2', 'streetAddress' => '1 High Street', 'city' => 'London', 'postalCode' => 'SW1A 1AA', 'country' => 'UK', 'type' => 'home'], $body['addresses'][0]);
		$this->assertSame([['name' => 'Contoso Ltd', 'department' => 'Research', 'title' => 'Head of Research']], $body['organizations']);
		$this->assertSame([['date' => ['month' => 5, 'day' => 17]]], $body['birthdays']);
		$this->assertSame([['value' => 'JQ']], $body['nicknames']);
		$this->assertSame('Met at the 2025 conference.', $body['biographies'][0]['value']);
	}

	public function testLocalVCardToPerson(): void {
		$card = VCards::parse(Fixtures::text('local/contact.vcf'));
		$body = $this->converter->toPerson($card);

		$this->assertSame([['givenName' => 'Sam', 'familyName' => "O'Neill", 'honorificPrefix' => 'Mr']], $body['names']);
		$this->assertSame(['work', 'home', 'other', 'other'], array_column($body['emailAddresses'], 'type'));
		$this->assertSame(['primary' => true], $body['emailAddresses'][1]['metadata']);
		$this->assertSame(['mobile', 'work', 'home', 'workFax'], array_column($body['phoneNumbers'], 'type'));
		$this->assertSame([['date' => ['month' => 8, 'day' => 30]]], $body['birthdays']);
		$this->assertSame('Bristol', $body['addresses'][0]['city']);
		$this->assertSame('Unit 4', $body['addresses'][0]['extendedAddress']);

		$photo = $this->converter->photoFor($card);
		$this->assertNotNull($photo);
		$this->assertSame('image/png', $photo['mime']);
		$this->assertStringStartsWith("\x89PNG", $photo['data']);
	}
}
