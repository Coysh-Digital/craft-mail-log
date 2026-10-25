<?php

namespace CoyshDigital\MailLog\helpers;

/**
 * Helpers for rendering a logged HTML body inside the CP preview iframe.
 */
class EmailPreview
{
    /**
     * Content Security Policy applied to previews when remote content is
     * blocked. Inline styles and embedded (data:) assets still render, but
     * nothing is fetched from the network, so viewing a log entry cannot
     * fire tracking pixels or leak the viewer's IP address to third parties.
     */
    public const BLOCK_REMOTE_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; media-src data:";

    /**
     * Inject a CSP <meta> tag into an HTML document so that the browser
     * refuses to load any remote resources it references.
     *
     * The tag is placed as early as possible in <head> (CSP meta tags are
     * only honoured there), without disturbing a leading doctype so the
     * document keeps its rendering mode.
     */
    public static function blockRemoteContent(string $html): string
    {
        $meta = sprintf(
            '<meta http-equiv="Content-Security-Policy" content="%s">',
            htmlspecialchars(self::BLOCK_REMOTE_CSP, ENT_QUOTES)
        );

        // Insert after the opening <head> tag, or failing that after <html>
        // (the parser then opens an implicit <head> for the meta tag), or
        // failing that after the doctype.
        foreach (['/<head\b[^>]*>/i', '/<html\b[^>]*>/i', '/<!doctype\b[^>]*>/i'] as $pattern) {
            if (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
                $offset = $match[0][1] + strlen($match[0][0]);
                return substr($html, 0, $offset) . $meta . substr($html, $offset);
            }
        }

        // A fragment: the meta tag goes into the implicit <head>.
        return $meta . $html;
    }
}
