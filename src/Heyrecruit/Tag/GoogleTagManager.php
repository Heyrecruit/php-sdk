<?php
declare(strict_types=1);

namespace Heyrecruit\Tag;

/**
 * Baut die Google-Tag-Manager-Snippets fuer Head und Body.
 */
final class GoogleTagManager {

	/**
	 * @param string|null $publicId The GTM container id. Empty input yields empty snippets.
	 *
	 * @return array{head: string, body: string}
	 */
	public static function snippets(?string $publicId): array {
		if ($publicId === null || $publicId === '') {
			return ['head' => '', 'body' => ''];
		}

		return [
			'head' => '<!-- Google Tag Manager -->'
				. '<script> (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\': '
				. 'new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0], '
				. 'j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src= '
				. '\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f); '
				. '})(window,document,\'script\',\'dataLayer\', ' . self::jsString($publicId) . ');</script> '
				. '<!-- End Google Tag Manager -->',
			// rawurlencode allein genuegt: seine Ausgabe kennt nur %-.0-9A-Z_a-z~, also kein Zeichen,
			// das aus dem Attribut ausbrechen koennte - ein htmlspecialchars darum ist messbar wirkungslos.
			'body' => '<!-- Google Tag Manager (noscript) -->'
				. '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id='
				. rawurlencode($publicId) . '" '
				. 'height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript> '
				. '<!-- End Google Tag Manager (noscript) -->',
		];
	}

	/**
	 * Kodiert einen Wert als JS-String-Literal inklusive Anfuehrungszeichen.
	 *
	 * @param string $value The value to encode.
	 *
	 * @return string
	 */
	private static function jsString(string $value): string {
		return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: "''";
	}
}
