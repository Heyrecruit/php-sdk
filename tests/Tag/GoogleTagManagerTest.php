<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Tag;

use Heyrecruit\Tag\GoogleTagManager;
use PHPUnit\Framework\TestCase;

final class GoogleTagManagerTest extends TestCase {

	public function testEmptyIdYieldsEmptySnippets(): void {
		$this->assertSame(['head' => '', 'body' => ''], GoogleTagManager::snippets(''));
		$this->assertSame(['head' => '', 'body' => ''], GoogleTagManager::snippets(null));
	}

	public function testRegularContainerIdIsEmbedded(): void {
		$snippets = GoogleTagManager::snippets('GTM-ABC1234');

		$this->assertStringContainsString('"GTM-ABC1234"', $snippets['head']);
		$this->assertStringContainsString('id=GTM-ABC1234"', $snippets['body']);
	}

	public function testBreakoutFromTheJsStringIsNeutralised(): void {
		$payload  = "GTM-X'); alert(1); ('";
		$snippets = GoogleTagManager::snippets($payload);

		$this->assertStringNotContainsString($payload, $snippets['head'], 'Der rohe Payload darf nicht im Script stehen.');
		$this->assertStringContainsString('\u0027', $snippets['head'], 'Das Apostroph muss escaped ankommen.');
	}

	public function testScriptTagCannotBeClosedFromTheId(): void {
		$snippets = GoogleTagManager::snippets('GTM-X</script><script>alert(1)</script>');

		$this->assertStringNotContainsString('</script><script>', $snippets['head']);
		$this->assertStringContainsString('\u003C', $snippets['head']);
	}

	public function testBreakoutFromTheIframeAttributeIsNeutralised(): void {
		$snippets = GoogleTagManager::snippets('GTM-X" onload="alert(1)');

		$this->assertStringNotContainsString('onload="alert(1)', $snippets['body']);
		$this->assertStringNotContainsString('" onload', $snippets['body']);
	}

	public function testSnippetsKeepTheirWrappingComments(): void {
		$snippets = GoogleTagManager::snippets('GTM-ABC1234');

		$this->assertStringStartsWith('<!-- Google Tag Manager -->', $snippets['head']);
		$this->assertStringEndsWith('<!-- End Google Tag Manager (noscript) -->', $snippets['body']);
	}
}
