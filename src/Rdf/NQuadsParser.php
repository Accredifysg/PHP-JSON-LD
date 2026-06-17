<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Rdf;

use Accredify\JsonLd\Exceptions\JsonLdException;

/**
 * A minimal, dependency-free N-Quads parser: the inverse of
 * {@see RdfQuad::toNQuads()} / {@see RdfTerm::toNQuads()}.
 *
 * Turns an N-Quads document into a list of {@see RdfQuad}, the input the
 * `FromRdf` algorithm consumes. It accepts the full W3C N-Quads escape
 * surface (`\t \b \n \r \f \" \' \\` and the
 * `\uXXXX` / `\UXXXXXXXX` UCHAR forms), comments, blank lines, and an optional
 * graph term, and round-trips with the serialisers above.
 *
 * Kept inside php-json-ld on purpose — the package takes no dependency on
 * `accredifysg/php-rdf-canonicalize` (which has its own N-Quads parser).
 */
final class NQuadsParser
{
    /**
     * @return list<RdfQuad>
     *
     * @throws JsonLdException on malformed input.
     */
    public function parse(string $input): array
    {
        $quads = [];
        foreach (explode("\n", $input) as $rawLine) {
            $line = rtrim($rawLine, "\r");
            $trimmed = ltrim($line, " \t");
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue; // blank line or comment
            }
            $quads[] = $this->parseStatement($line);
        }

        return $quads;
    }

    private function parseStatement(string $line): RdfQuad
    {
        $pos = 0;
        $subject = $this->readTerm($line, $pos, allowLiteral: false);
        $predicate = $this->readTerm($line, $pos, allowLiteral: false);
        $object = $this->readTerm($line, $pos, allowLiteral: true);

        $this->skipWhitespace($line, $pos);
        $graph = null;
        if ($pos < strlen($line) && $line[$pos] !== '.') {
            $graph = $this->readTerm($line, $pos, allowLiteral: false);
            $this->skipWhitespace($line, $pos);
        }

        if ($pos >= strlen($line) || $line[$pos] !== '.') {
            throw new JsonLdException("Malformed N-Quads statement (expected '.'): {$line}");
        }

        return new RdfQuad($subject, $predicate, $object, $graph);
    }

    private function readTerm(string $line, int &$pos, bool $allowLiteral): RdfTerm
    {
        $this->skipWhitespace($line, $pos);
        if ($pos >= strlen($line)) {
            throw new JsonLdException("Unexpected end of N-Quads statement: {$line}");
        }

        $c = $line[$pos];
        if ($c === '<') {
            return RdfTerm::iri($this->unescape($this->readDelimited($line, $pos, '>')));
        }
        if ($c === '_' && ($line[$pos + 1] ?? '') === ':') {
            return RdfTerm::blankNode($this->readBlankNodeLabel($line, $pos));
        }
        if ($c === '"') {
            if (! $allowLiteral) {
                throw new JsonLdException("A literal is not allowed in subject, predicate, or graph position: {$line}");
            }

            return $this->readLiteral($line, $pos);
        }

        throw new JsonLdException("Unexpected character '{$c}' in N-Quads statement: {$line}");
    }

    /**
     * Reads an escape-aware delimited run. Assumes `$line[$pos]` is the opening
     * delimiter (`<` or `"`); returns the still-escaped inner text and advances
     * `$pos` past the matching close delimiter. A backslash escapes the next
     * character, so a `\"` / `\>` / `\\` never terminates the run prematurely.
     */
    private function readDelimited(string $line, int &$pos, string $close): string
    {
        $len = strlen($line);
        $pos++; // skip the opening delimiter
        $raw = '';
        while ($pos < $len) {
            $ch = $line[$pos];
            if ($ch === '\\') {
                $raw .= $ch;
                $pos++;
                if ($pos < $len) {
                    $raw .= $line[$pos];
                    $pos++;
                }

                continue;
            }
            if ($ch === $close) {
                $pos++;

                return $raw;
            }
            $raw .= $ch;
            $pos++;
        }

        throw new JsonLdException("Unterminated '{$close}'-delimited term in N-Quads statement: {$line}");
    }

    private function readBlankNodeLabel(string $line, int &$pos): string
    {
        $pos += 2; // skip "_:"
        $len = strlen($line);
        $label = '';
        while ($pos < $len && preg_match('/[A-Za-z0-9_.\-]/', $line[$pos]) === 1) {
            $label .= $line[$pos];
            $pos++;
        }
        // A blank-node label may not end with '.' (the dot is significant only
        // mid-label); push any trailing dots back.
        while ($label !== '' && str_ends_with($label, '.')) {
            $label = substr($label, 0, -1);
            $pos--;
        }
        if ($label === '') {
            throw new JsonLdException("Empty blank-node label in N-Quads statement: {$line}");
        }

        return '_:'.$label;
    }

    private function readLiteral(string $line, int &$pos): RdfTerm
    {
        $value = $this->unescape($this->readDelimited($line, $pos, '"'));
        $len = strlen($line);

        if ($pos < $len && $line[$pos] === '@') {
            $pos++;
            $lang = '';
            while ($pos < $len && preg_match('/[A-Za-z0-9-]/', $line[$pos]) === 1) {
                $lang .= $line[$pos];
                $pos++;
            }
            if ($lang === '') {
                throw new JsonLdException("Empty language tag in N-Quads statement: {$line}");
            }

            return RdfTerm::literal($value, null, $lang);
        }

        if ($pos + 1 < $len && $line[$pos] === '^' && $line[$pos + 1] === '^') {
            $pos += 2;
            if (($line[$pos] ?? '') !== '<') {
                throw new JsonLdException("Expected a datatype IRI after '^^' in N-Quads statement: {$line}");
            }

            return RdfTerm::literal($value, $this->unescape($this->readDelimited($line, $pos, '>')));
        }

        return RdfTerm::literal($value);
    }

    private function unescape(string $s): string
    {
        if (! str_contains($s, '\\')) {
            return $s;
        }

        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c !== '\\') {
                $out .= $c;
                $i++;

                continue;
            }

            $next = $s[$i + 1] ?? '';
            switch ($next) {
                case 't': $out .= "\t";
                    $i += 2;
                    break;
                case 'b': $out .= "\x08";
                    $i += 2;
                    break;
                case 'n': $out .= "\n";
                    $i += 2;
                    break;
                case 'r': $out .= "\r";
                    $i += 2;
                    break;
                case 'f': $out .= "\x0C";
                    $i += 2;
                    break;
                case '"': $out .= '"';
                    $i += 2;
                    break;
                case "'": $out .= "'";
                    $i += 2;
                    break;
                case '\\': $out .= '\\';
                    $i += 2;
                    break;
                case 'u': $out .= $this->codepoint(substr($s, $i + 2, 4), 4, $s);
                    $i += 6;
                    break;
                case 'U': $out .= $this->codepoint(substr($s, $i + 2, 8), 8, $s);
                    $i += 10;
                    break;
                default:
                    throw new JsonLdException("Invalid escape '\\{$next}' in N-Quads literal: {$s}");
            }
        }

        return $out;
    }

    private function codepoint(string $hex, int $width, string $context): string
    {
        if (strlen($hex) !== $width || ! ctype_xdigit($hex)) {
            throw new JsonLdException("Invalid Unicode escape in N-Quads literal: {$context}");
        }
        $codepoint = (int) hexdec($hex);
        if ($codepoint > 0x10FFFF) {
            throw new JsonLdException("Unicode code point out of range in N-Quads literal: {$context}");
        }

        return mb_chr($codepoint, 'UTF-8');
    }

    private function skipWhitespace(string $line, int &$pos): void
    {
        $len = strlen($line);
        while ($pos < $len && ($line[$pos] === ' ' || $line[$pos] === "\t")) {
            $pos++;
        }
    }
}
