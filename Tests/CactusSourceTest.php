<?php

namespace Omnilab\Cactus\Tests;

use Omnilab\Cactus\CactusSource;
use Omnilab\Cactus\CactusSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from cactus.nci.nih.gov on 2026-10-15, each
 * representation of caffeine (by its name and its CAS number), of "CCO"
 * (a SMILES: ethanol) and of aspirin by its InChIKey; an empty XML answer
 * for "zzqqxx", and OPSIN's 404 for it; OPSIN's answer for
 * "2-methyl-3-(2-methylcyclopropyl)pentanedioic acid" and CIR's for the
 * InChI OPSIN gave (CIR read that name itself that day: the test makes it
 * fail, to follow the fallback).
 */
final class CactusSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @param list<string> $unreadable names CIR is made not to read */
    private function source(?MockResponse $response = null, array $options = [], array $unreadable = []): CactusSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response, $unreadable): MockResponse {
            $path = rawurldecode(str_starts_with($url, CactusSource::BASE_URI) ? substr($url, \strlen(CactusSource::BASE_URI)) : $url);
            $this->calls[] = $path;
            if ($response) {
                return $response;
            }
            if (str_starts_with($url, CactusSource::OPSIN_URI)) {
                $name = 'opsin_'.trim((string) preg_replace('~[^A-Za-z0-9]+~', '_', basename($path)), '_');
            } elseif (str_contains($path, 'C10H16O4')) {
                $name = 'InChI_C10H16O4_'.str_replace('/', '_', substr($path, strrpos($path, '/', str_ends_with($path, '/xml') ? -5 : 0) + 1));
            } else {
                $structure = substr($path, 0, strpos($path, '/stdinchikey/xml') ?: strrpos($path, '/'));
                $name = \in_array($structure, $unreadable, true) ? 'zzqqxx_stdinchikey_xml' : trim((string) preg_replace('~[^A-Za-z0-9]+~', '_', $path), '_');
            }
            foreach (['' => 200, '.404' => 404] as $suffix => $status) {
                if (is_file($file = __DIR__.'/Fixtures/'.$name.$suffix)) {
                    return new MockResponse((string) file_get_contents($file), ['http_code' => $status]);
                }
            }
            self::fail('no fixture for '.$path.' ('.$name.')');
        });
        $source = (new CactusSourceFactory($http))->create(['names' => 5] + $options);
        self::assertInstanceOf(CactusSource::class, $source);

        return $source;
    }

    public function testCaffeineByNameEightCalls(): void
    {
        $caffeine = $this->source()->entity('caffeine');

        self::assertSame(['caffeine/stdinchikey/xml', 'caffeine/stdinchi', 'caffeine/smiles', 'caffeine/iupac_name', 'caffeine/formula', 'caffeine/mw', 'caffeine/cas', 'caffeine/names'], $this->calls);
        self::assertSame('inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->id->key());
        self::assertSame('caffeine', $caffeine->label, 'the name asked, read as one');
        self::assertSame(Domain::CHEMISTRY, $caffeine->domain);
        self::assertSame('compound', $caffeine->kind);
        self::assertSame('IUPAC name (OPSIN)', $caffeine->value('read_as'));
        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'));
        self::assertEquals(new Quantity(194.1926, 'g/mol'), $caffeine->value('molecular_weight'));
        self::assertSame('1,3,7-trimethylpurine-2,6-dione', $caffeine->value('iupac_name'));
        self::assertSame('Cn1cnc2N(C)C(=O)N(C)C(=O)c12', $caffeine->value('smiles'));
        self::assertSame('InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3', $caffeine->identifier(Scheme::INCHI));
        self::assertSame(['71701-02-5', '95789-13-2', '58-08-2'], $caffeine->identifiers->values(Scheme::CAS), 'CAS\'s numbers, deleted ones included, each checked');
        self::assertCount(5, $caffeine->synonyms);
        self::assertSame('1,3,7-trimethylpurine-2,6-dione', $caffeine->synonyms[0]);
        self::assertNotContains('58-08-2', $caffeine->synonyms, 'a CAS number is no name');
        self::assertStringStartsWith(CactusSource::BASE_URI.'InChI%3D1S/C8H10N4O2/', (string) $caffeine->image);
        self::assertStringEndsWith('/image', (string) $caffeine->image);
    }

    public function testASmilesAndACasNumberAndAnInchiKey(): void
    {
        $ethanol = $this->source()->entity('CCO');
        self::assertSame('inchikey:LFQSCWFLJHTTHZ-UHFFFAOYSA-N', $ethanol->id->key());
        self::assertSame('SMILES string', $ethanol->value('read_as'));
        self::assertSame('ethanol', $ethanol->label, 'a SMILES is no name: CIR\'s first');
        self::assertContains('64-17-5', $ethanol->identifiers->values(Scheme::CAS));

        $caffeine = $this->source()->entity(Identifier::cas('58-08-2'));
        self::assertSame('inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->id->key());
        self::assertSame('chemical name (CIR)', $caffeine->value('read_as'));
        self::assertSame('1,3,7-trimethylpurine-2,6-dione', $caffeine->label);

        $this->calls = [];
        $aspirin = $this->source()->entity('BSYNRYMUTXBXSQ-UHFFFAOYSA-N');
        self::assertSame('InChIKey=BSYNRYMUTXBXSQ-UHFFFAOYSA-N/stdinchikey/xml', $this->calls[0], 'an InChIKey asked as CIR names it');
        self::assertSame('Standard InChIKey', $aspirin->value('read_as'));
        self::assertSame('C9H8O4', $aspirin->value('molecular_formula'));
        self::assertSame('2-acetyloxybenzoic acid', $aspirin->label);
        self::assertContains('50-78-2', $aspirin->identifiers->values(Scheme::CAS));
    }

    public function testANameCirCannotReadGoesToOpsinThenCirByTheInchi(): void
    {
        $name = '2-methyl-3-(2-methylcyclopropyl)pentanedioic acid';
        $acid = $this->source(unreadable: [$name])->entity($name);

        self::assertSame($name.'/stdinchikey/xml', $this->calls[0]);
        self::assertSame(CactusSource::OPSIN_URI.$name.'.json', $this->calls[1]);
        self::assertStringStartsWith('InChI=1S/C10H16O4/', $this->calls[2]);
        self::assertSame('inchikey:FXGUYTPXVQMBEG-UHFFFAOYSA-N', $acid->id->key());
        self::assertSame($name, $acid->label);
        self::assertSame('IUPAC name (OPSIN web service)', $acid->value('read_as'));
        self::assertSame('C10H16O4', $acid->value('molecular_formula'));
        self::assertEquals(new Quantity(200.234, 'g/mol'), $acid->value('molecular_weight'));
        self::assertSame(['cactus', 'opsin'], array_values(array_unique(array_map(static fn ($p) => $p->source, $acid->properties('inchikey')))));
        self::assertNull($acid->value('iupac_name'));
        self::assertCount(0, $acid->identifiers->all(Scheme::CAS));

        $this->calls = [];
        self::assertNull($this->source(options: ['opsin' => false], unreadable: [$name])->entity($name));
        self::assertCount(1, $this->calls, 'OPSIN off: CIR alone');
    }

    public function testUnknownIsNullAndOtherBasesRefused(): void
    {
        self::assertNull($this->source()->entity('zzqqxx'));
        self::assertSame(['zzqqxx/stdinchikey/xml', CactusSource::OPSIN_URI.'zzqqxx.json'], $this->calls);
        $this->calls = [];
        foreach (['pubchem.cid:2519', 'CHEBI:27732', 'Q60235', '  '] as $id) {
            try {
                $this->source()->entity($id);
                self::fail($id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testCirsPageNotFoundIn500IsNullAnotherErrorIsNot(): void
    {
        self::assertNull($this->source(new MockResponse('<h1>Page not found (404)</h1>', ['http_code' => 500]), ['opsin' => false])->entity('zzqqxx'));
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('<h1>Server Error (500)</h1>', ['http_code' => 500]))->entity('caffeine');
    }

    public function testOptionsAndCapabilities(): void
    {
        $source = $this->source(options: ['representations' => ['formula'], 'names' => 0]);
        $caffeine = $source->entity('caffeine');
        self::assertSame(['caffeine/stdinchikey/xml', 'caffeine/formula'], $this->calls);
        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'));
        self::assertSame([Capability::ENTITY, Capability::STRUCTURE, Capability::CROSS_REFERENCES], $source->capabilities());
        $this->expectException(\InvalidArgumentException::class);
        new CactusSource(new MockHttpClient(), ['sdf']);
    }
}
