<?php

namespace Omnilab\Cactus;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The NCI/CADD Chemical Identifier Resolver: a structure from a name, a
 * CAS number, an InChIKey, an InChI or a SMILES, and its other
 * identifiers and representations; OPSIN for a name it cannot read - no
 * key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     representations: [stdinchi, smiles, iupac_name, formula, mw, cas, names]   # one call each, after the InChIKey
 *     names: 20                        # how many names to keep as synonyms (0: the names are not asked)
 *     opsin: true                      # a name CIR cannot read is given to OPSIN (false: never; or OPSIN's address)
 *     throttle: 0                      # seconds between two calls: CIR publishes no rate
 *     base_uri: 'https://cactus.nci.nih.gov/chemical/structure/'
 */
final class CactusSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'cactus',
            'omnilab.required_options' => [],
            'representations' => CactusSource::REPRESENTATIONS,
            'names' => 20,
            'opsin' => true,
            'throttle' => 0.0,
            'base_uri' => CactusSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $opsin = $c->get('opsin');

        return new CactusSource(
            $this->http ?? HttpClient::create(),
            array_values(array_map('strval', (array) $c->get('representations'))),
            (int) $c->get('names'),
            (string) $c->get('base_uri', CactusSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
            match (true) {
                \is_string($opsin) && '' !== $opsin => $opsin,
                (bool) $opsin => CactusSource::OPSIN_URI,
                default => null,
            },
        );
    }
}
