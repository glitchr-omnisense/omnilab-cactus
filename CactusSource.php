<?php

namespace Omnilab\Cactus;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The NCI/CADD Chemical Identifier Resolver (CIR, cactus.nci.nih.gov):
 * a structure from whatever names it - a name ("caffeine", "acetylsalicylic
 * acid"), a CAS number, an InChIKey, a standard InChI, a SMILES - turned
 * into the others: its standard InChIKey (its id here: CIR keeps none of
 * its own), standard InChI, SMILES, IUPAC name, formula, molecular weight,
 * CAS numbers and names, and a picture of it.
 *
 * A name CIR cannot resolve is given to OPSIN (the University of
 * Cambridge's IUPAC name parser, served by the EBI), once, unless `opsin`
 * is off: CIR already reads systematic names with OPSIN first (its
 * resolver "name_by_opsin"), so OPSIN is no other base - no identifier,
 * no record - but the current parser, asked when CIR's fails or is out of
 * date; what it gives (standard InChI and InChIKey, SMILES) is then asked
 * of CIR by the InChI for the rest.
 *
 * One call a representation: up to eight an entity (the InChIKey first,
 * in CIR's XML, which says what CIR read the string as; nothing more is
 * asked of what CIR cannot resolve), one more to OPSIN for a name CIR
 * does not know. CIR answers text; a structure it cannot resolve is an
 * empty XML answer, a 404, or a 500 whose page says "Page not found":
 * null. No key, no published rate.
 */
final class CactusSource extends HttpSource
{
    public const BASE_URI = 'https://cactus.nci.nih.gov/chemical/structure/';

    /** What entity() asks after the InChIKey, by default. */
    public const REPRESENTATIONS = ['stdinchi', 'smiles', 'iupac_name', 'formula', 'mw', 'cas', 'names'];

    public const OPSIN_URI = 'https://www.ebi.ac.uk/opsin/ws/';

    private float $last = 0.0;

    /**
     * @param list<string> $representations what entity() asks after the InChIKey (of self::REPRESENTATIONS)
     * @param int          $names           how many names to keep as synonyms
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly array $representations = self::REPRESENTATIONS,
        private readonly int $names = 20,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
        private readonly ?string $opsinUri = self::OPSIN_URI,
    ) {
        $unknown = array_diff($representations, self::REPRESENTATIONS);
        if ($unknown) {
            throw new \InvalidArgumentException(\sprintf('CIR\'s representations here are %s, not "%s".', implode(', ', self::REPRESENTATIONS), implode('", "', $unknown)));
        }
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'cactus';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::STRUCTURE, Capability::CROSS_REFERENCES];
    }

    /**
     * A structure by an InChIKey, a standard InChI or a CAS number (as an
     * Identifier or a string), or by a name or a SMILES (a string no
     * scheme reads); an identifier of another base is refused before any
     * call (CIR does not resolve PubChem's or ChEBI's ids); null when CIR
     * cannot resolve it.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $structure = $this->structure($id);
        // The XML says what CIR read the string as (a SMILES, a name by OPSIN or by CIR's tables, a CAS number...), the first reading being the one the text answers give.
        $xml = (string) $this->cir($structure, 'stdinchikey/xml');
        $resolved = preg_match('~<data[^>]*\bresolver="([^"]*)"[^>]*\bstring_class="([^"]*)"[^>]*>\s*<item[^>]*>\s*(?:InChIKey=)?([A-Z-]+)\s*</item>~', $xml, $m) ? $m : null;
        $inchikey = Identifier::tryOf(Scheme::INCHIKEY, $resolved[3] ?? null);
        $opsin = null;
        if (null === $inchikey) {
            $opsin = $this->opsin($id, $structure);
            $inchikey = Identifier::tryOf(Scheme::INCHIKEY, $opsin['stdinchikey'] ?? null);
            if (null === $inchikey || null === ($inchi = Identifier::tryOf(Scheme::INCHI, $opsin['stdinchi'] ?? null))) {
                return null;
            }
            // CIR is asked the rest by the InChI OPSIN gave: it computes a formula and a weight for any structure.
            $structure = $inchi->value;
        }
        $answers = [];
        foreach ($this->representations as $representation) {
            if ('names' === $representation && $this->names <= 0) {
                continue;
            }
            $answers[$representation] = $this->cir($structure, $representation);
        }

        return $this->toEntity($inchikey, $answers, $id, $resolved ? html_entity_decode($resolved[2], \ENT_QUOTES | \ENT_XML1, 'UTF-8') : null, $resolved[1] ?? null, $opsin);
    }

    /**
     * OPSIN's reading of a name CIR could not resolve; null when OPSIN is
     * off, when what was asked is not a name, or when OPSIN cannot parse
     * it (its 404 and "FAILURE").
     *
     * @return array<string, mixed>|null
     */
    private function opsin(Identifier|string $id, string $name): ?array
    {
        if (null === $this->opsinUri || '' === $this->opsinUri || $id instanceof Identifier || null !== Identifier::parse($id)) {
            return null;
        }
        $answer = $this->getJson(rtrim($this->opsinUri, '/').'/'.rawurlencode($name).'.json');

        return 'SUCCESS' === ($answer['status'] ?? null) ? $answer : null;
    }

    /** What CIR is asked about: the structure's string, slashes left as they are (CIR's server refuses %2F). */
    private function structure(Identifier|string $id): string
    {
        $identifier = $id instanceof Identifier ? $id : Identifier::parse($id);
        if (null !== $identifier) {
            if (!\in_array($identifier->scheme, [Scheme::INCHIKEY, Scheme::INCHI, Scheme::CAS], true)) {
                throw NotSupportedException::identifier('cactus', (string) $id);
            }

            return Scheme::INCHIKEY === $identifier->scheme ? 'InChIKey='.$identifier->value : $identifier->value;
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $id));
        if ('' === $text) {
            throw NotSupportedException::identifier('cactus', (string) $id);
        }

        return $text;
    }

    /**
     * One representation of a structure; null when CIR cannot resolve it
     * (404, or the 500 whose page says "Page not found").
     */
    private function cir(string $structure, string $representation): ?string
    {
        if ($this->throttle > 0 && $this->last > 0 && ($left = $this->throttle - (microtime(true) - $this->last)) > 0) {
            usleep((int) ($left * 1_000_000));
        }
        $this->last = microtime(true);
        $url = rtrim($this->baseUri, '/').'/'.str_replace('%2F', '/', rawurlencode($structure)).'/'.$representation;
        try {
            $response = $this->http->request('GET', $url, ['headers' => $this->headers]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new UnavailableException('cactus', 'unreachable: '.$e->getMessage(), null, $e);
        } catch (ExceptionInterface $e) {
            throw new ProviderException('cactus', $e->getMessage(), null, $e);
        }

        return match (true) {
            200 === $status => '' === trim($body) ? null : $body,
            404 === $status, 500 === $status && str_contains($body, 'Page not found') => null,
            429 === $status => throw new RateLimitedException('cactus'),
            $status >= 500 => throw new UnavailableException('cactus', \sprintf('the service answered %d', $status), $status),
            default => throw new ProviderException('cactus', \sprintf('the service answered %d', $status), $status),
        };
    }

    /**
     * @param array<string, string|null> $answers
     * @param array<string, mixed>|null  $opsin   OPSIN's answer, when CIR could not read the name
     */
    private function toEntity(Identifier $inchikey, array $answers, Identifier|string $asked, ?string $readAs, ?string $resolver, ?array $opsin): Entity
    {
        $lines = static fn (?string $text): array => array_values(array_filter(array_map('trim', preg_split('~\R~', (string) $text) ?: []), static fn (string $l) => '' !== $l));
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'cactus');
            }
        };
        $inchi = Identifier::tryOf(Scheme::INCHI, $lines($answers['stdinchi'] ?? null)[0] ?? $opsin['stdinchi'] ?? null);
        $add('read_as', 'What CIR read it as', null !== $opsin ? 'IUPAC name (OPSIN web service)' : $readAs);
        $add('molecular_formula', 'Molecular formula', $lines($answers['formula'] ?? null)[0] ?? null);
        $mw = $lines($answers['mw'] ?? null)[0] ?? null;
        $add('molecular_weight', 'Molecular weight', is_numeric($mw) ? new Quantity((float) $mw, 'g/mol') : null);
        $add('iupac_name', 'IUPAC name', $lines($answers['iupac_name'] ?? null)[0] ?? null);
        $add('smiles', 'SMILES', $lines($answers['smiles'] ?? null)[0] ?? null);
        $add('inchi', 'InChI', $inchi?->value);
        $add('inchikey', 'InChIKey', $inchikey->value);
        if (null !== $opsin) {
            foreach (['smiles' => 'SMILES', 'stdinchi' => 'InChI', 'stdinchikey' => 'InChIKey'] as $key => $label) {
                if (\is_string($opsin[$key] ?? null) && '' !== $opsin[$key]) {
                    $properties[] = new Property('stdinchi' === $key ? 'inchi' : ('stdinchikey' === $key ? 'inchikey' : $key), $label, $opsin[$key], 'opsin');
                }
            }
        }

        // CIR's CAS list holds the numbers CAS has deleted or merged too: each checked, each an identifier.
        $cas = array_map(static fn (string $n) => Identifier::tryOf(Scheme::CAS, $n), $lines($answers['cas'] ?? null));
        $names = array_values(array_filter($lines($answers['names'] ?? null), static fn (string $n) => null === Identifier::tryOf(Scheme::CAS, $n)));
        $iupac = $lines($answers['iupac_name'] ?? null)[0] ?? null;
        // The name asked, when CIR (or OPSIN) read it as a name; else CIR's first name, its IUPAC name, the InChIKey.
        $name = \is_string($asked) && (null !== $opsin || str_starts_with((string) $resolver, 'name_by_')) && null === Identifier::parse($asked) ? trim($asked) : null;
        $label = $name ?? $names[0] ?? $iupac ?? $inchikey->value;

        return new Entity(
            id: $inchikey,
            label: $label,
            domain: Domain::CHEMISTRY,
            kind: 'compound',
            synonyms: \array_slice(array_values(array_filter($names, static fn (string $n) => $n !== $label)), 0, max(0, $this->names)),
            properties: $properties,
            identifiers: new Identifiers([$inchi, ...$cas]),
            // By the InChI when there is one: CIR draws any structure from it, an InChIKey only from its tables.
            image: rtrim($this->baseUri, '/').'/'.str_replace('%2F', '/', rawurlencode(null !== $inchi ? $inchi->value : 'InChIKey='.$inchikey->value)).'/image',
            source: 'cactus',
        );
    }
}
