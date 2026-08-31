<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Auth;

use Heyrecruit\Auth\AccessToken;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase {

	public function testPayloadWithoutTokenYieldsNothing(): void {
		$this->assertNull(AccessToken::fromArray([]));
		$this->assertNull(AccessToken::fromArray(['token' => '']));
		$this->assertNull(AccessToken::fromArray(['token' => 12345]));
		$this->assertNull(AccessToken::fromArray(['token' => null, 'expiration' => time() + 60]));
	}

	public function testControlCharactersInTheTokenAreRejected(): void {
		// Der Token geht als Authorization-Header raus; ein CR/LF darin erzeugte eine zweite Header-Zeile.
		$carriageReturnLineFeed = chr(13) . chr(10);
		$lineFeed               = chr(10);
		$nul                    = chr(0);

		foreach ([$carriageReturnLineFeed, $lineFeed, $nul, chr(127)] as $control) {
			$this->assertNull(
				AccessToken::fromArray(["token" => "abc" . $control . "X-Injected: pwned", "expiration" => time() + 60]),
				sprintf("Steuerzeichen 0x%02X muss abgewiesen werden.", ord(substr($control, 0, 1)))
			);
		}
	}

	public function testRealJwtShapeIsAccepted(): void {
		$jwt = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOjF9.-_aBc123XYZ";
		$this->assertSame($jwt, AccessToken::fromArray(["token" => $jwt, "expiration" => time() + 60])?->token);
	}

	public function testMarginIsSubtractedOnce(): void {
		$expiration = time() + 3600;

		$token = AccessToken::fromArray(['token' => 'jwt', 'expiration' => $expiration], 60);

		$this->assertNotNull($token);
		$this->assertSame($expiration - 60, $token->expiresAt);
	}

	public function testTokenWithoutExpirationCountsAsExpired(): void {
		$token = AccessToken::fromArray(['token' => 'jwt']);

		$this->assertNotNull($token);
		$this->assertTrue($token->isExpired());
	}

	public function testFutureExpirationIsNotExpired(): void {
		$this->assertFalse((new AccessToken('jwt', time() + 30))->isExpired());
	}

	public function testExpirationInThePastOrNowIsExpired(): void {
		$this->assertTrue((new AccessToken('jwt', time() - 1))->isExpired());
		$this->assertTrue((new AccessToken('jwt', time()))->isExpired());
	}

	public function testStorageFormKeepsTheTwoPointXKeys(): void {
		$token = new AccessToken('jwt', 1750000000);

		$this->assertSame(['token' => 'jwt', 'expiration' => 1750000000], $token->toArray());
	}

	public function testRoundTripThroughTheStorageFormIsLossless(): void {
		$token = new AccessToken('jwt', time() + 120);

		$restored = AccessToken::fromArray($token->toArray());

		$this->assertNotNull($restored);
		$this->assertSame($token->token, $restored->token);
		$this->assertSame($token->expiresAt, $restored->expiresAt);
	}
}
