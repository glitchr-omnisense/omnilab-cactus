# omnilab/cactus

The NCI/CADD Chemical Identifier Resolver (CIR: `https://cactus.nci.nih.gov/chemical/structure/`)
for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a structure from a name, a
CAS number, an InChIKey, an InChI or a SMILES - its standard InChIKey (its id), InChI, SMILES,
IUPAC name, formula, weight, CAS numbers, names and picture -, as an entity; OPSIN (the EBI's IUPAC
name parser) for a name CIR cannot read. No key.

## Installation

```sh
composer require omnilab/cactus
```

```php
use Omnilab\Cactus\CactusSourceFactory;

$cactus = (new CactusSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'representations' => ['stdinchi', 'smiles', 'iupac_name', 'formula', 'mw', 'cas', 'names'],  // optional: one call each
    'names' => 20,                     // optional: synonyms kept; 0: the names are not asked
    'opsin' => true,                   // optional: false, or another OPSIN's address (https://.../opsin/ws/)
]);
```

## Credentials

None: CIR and OPSIN's web service take no key and no account.

## Options

The factory's options, with the defaults it applies (`CactusSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `representations` | `list<string>` | `[stdinchi, smiles, iupac_name, formula, mw, cas, names]` | no | what `entity()` asks after the InChIKey, one call each, of `stdinchi`, `smiles`, `iupac_name`, `formula`, `mw`, `cas`, `names` (another is an `\InvalidArgumentException` at `create()`) |
| `names` | `int` | `20` | no | how many of CIR's names to keep as synonyms; 0: the names are not asked |
| `opsin` | `bool\|string` | `true` | no | a name CIR cannot read is given to OPSIN: `true` (the EBI's, `https://www.ebi.ac.uk/opsin/ws/`), `false` (never), or another OPSIN's address |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: CIR publishes no rate |
| `base_uri` | `string` | `https://cactus.nci.nih.gov/chemical/structure/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `structure`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a name, a CAS number, an InChIKey, an InChI or a SMILES (an `Identifier` of `inchikey`, `inchi`, `cas`, or a string); another base's identifier refused before any call; one call for the InChIKey, then one per representation (eight by default) |
| `search` | no | CIR resolves one structure; it does not search |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | yes | the image is CIR's picture of the structure, by its InChI |
| `cross_references` | yes | the standard InChIKey (its id), the standard InChI and the CAS numbers |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## What it reads

`entity()` takes an `Identifier` of the `inchikey`, `inchi` or `cas` scheme, or a string: one of
those, or a name, or a SMILES (what no scheme reads). An identifier of another base (`pubchem.cid`,
`chebi`, `wikidata`...) is refused before any call: CIR does not resolve them. The entity's id is
the standard InChIKey (CIR keeps no id of its own); `identifiers` hold the standard InChI and the
CAS numbers CIR gives (CAS's deleted and merged numbers too: CIR lists them, each checked).

## The calls

| | Calls |
|---|---|
| first | `{structure}/stdinchikey/xml`: the InChIKey, and what CIR read the string as (`string_class`: "IUPAC name (OPSIN)", "SMILES string", "chemical name (CIR)", "Standard InChIKey"...). CIR may read a string several ways (`CCO`: a SMILES, ethanol; and a name of another compound); the text answers give the first reading, as this one keeps |
| then | one call each for `stdinchi`, `smiles`, `iupac_name`, `formula`, `mw`, `cas`, `names` (the `representations` option) |
| a name CIR cannot read | `www.ebi.ac.uk/opsin/ws/{name}.json` (OPSIN): its standard InChI, InChIKey and SMILES (properties of source `opsin`); then CIR by that InChI for the rest (CIR computes a formula and a weight for any structure; a name or CAS number only when its tables hold the structure) |

The structure is written in the path as CIR wants it: encoded, but its slashes left as they are
(CIR's server answers 404 to `%2F`: an InChI or a SMILES with `/` would be unreadable). Unknown
is an XML answer without data (and OPSIN's 404 or `FAILURE`); a text representation CIR does not
have is a 404, or a 500 whose page says "Page not found" (CIR's answer for a name it cannot read,
in text): null either way. Another 5xx is an `UnavailableException`.

## What an entity holds

`label`: the name asked, when CIR (or OPSIN) read the string as a name; else CIR's first name, its
IUPAC name, the InChIKey. `synonyms`: CIR's names (`names` of them), the CAS numbers left out.
`image`: CIR's picture of the structure, by its InChI. `domain` chemistry, `kind` `compound`.

| Key | Representation | Value |
|---|---|---|
| `read_as` | the XML's `string_class` | what CIR read the string as; `IUPAC name (OPSIN web service)` when OPSIN read it |
| `molecular_formula` | formula | `C8H10N4O2` |
| `molecular_weight` | mw | g/mol (`194.1926`) |
| `iupac_name` | iupac_name | text |
| `smiles` | smiles | CIR's SMILES (and OPSIN's, source `opsin`) |
| `inchi`, `inchikey` | stdinchi, stdinchikey | standard (and OPSIN's, source `opsin`) |

## The Collector

The InChIKey, the InChI and the CAS numbers are identifiers other sources read: with PubChem (or
ChEBI, ChEMBL) configured, `$collector->collect('cactus', 'caffeine', 1, ['pubchem'])` follows the
InChIKey into PubChem - walked on 2026-10-15 (the workspace's `.samples/live.php`, not published). CIR is the entry point for
what has no id yet: a name, a SMILES, a drawn structure's InChI.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Cactus\CactusSourceFactory;
use Omnilab\Model\Scheme;
use Symfony\Component\HttpClient\HttpClient;

$cactus = (new CactusSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'names' => 3]);

$caffeine = $cactus->entity('caffeine');
echo $caffeine->label, ' (', $caffeine->id, '), read as ', $caffeine->value('read_as'), ': ', $caffeine->value('molecular_formula'), ', ', $caffeine->property('molecular_weight'), "\n";
echo '  CAS ', implode(', ', $caffeine->identifiers()->values(Scheme::CAS)), '; names: ', implode(', ', $caffeine->synonyms), "\n";

$butene = $cactus->entity('C/C=C/C');   // a SMILES, its slashes kept raw in the path
echo $butene->label, ' (', $butene->id, '), read as ', $butene->value('read_as'), "\n";
var_dump($cactus->entity('zzqqxx'));
```

```
caffeine (inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N), read as IUPAC name (OPSIN): C8H10N4O2, 194.1926 g/mol
  CAS 71701-02-5, 95789-13-2, 58-08-2; names: 1,3,7-trimethylpurine-2,6-dione, 1,3,7-Trimethylxanthine, SDCCGMLS-0064595.P002
(E)-But-2-ene (inchikey:IAQRGUVFOMOMEM-ONEGZZNKSA-N), read as SMILES string
NULL
```

(as answered on 2026-10-27; CIR lists caffeine's deleted and merged CAS numbers beside 58-08-2, each
a valid CAS number; "zzqqxx" is an empty XML answer, then OPSIN's 404: null)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        cactus: { factory: cactus, options: { mailto: '%env(OMNILAB_MAILTO)%', names: 20, opsin: true } }
```

## Errors

CIR's calls are made by the source itself (the structure's slashes must stay raw in the path), and
mapped as `HttpSource` maps them: a 404, or a 500 whose page says "Page not found" (CIR's answer
for a name it cannot read in text), is null; a 429 a `RateLimitedException` (its `retryAfter`
not read); another 5xx, a time-out or a host that does not answer an `UnavailableException`; any
other 4xx a `ProviderException` with its status (CIR's body is not read into the message). The
throttle applies to these calls too. OPSIN's 404 or `FAILURE` is null.

Before any call: an identifier of a base CIR does not resolve (`pubchem.cid`, `chebi`,
`wikidata`...) (`NotSupportedException`).

## Verified, and not

Against the real services on 2026-10-15: caffeine by its name (read by CIR's OPSIN), by its CAS
number (read as a name by CIR's tables) and by InChIKey; "CCO" (read as a SMILES: ethanol, though
CIR also reads it as another compound's name); aspirin by its InChIKey; an InChI whose structure CIR
holds no name for (its formula and weight computed; no IUPAC name, CAS or names: 404s); "zzqqxx"
(an empty XML answer; OPSIN's 404); OPSIN's answer for
"2-methyl-3-(2-methylcyclopropyl)pentanedioic acid"; a SMILES with slashes (`C/C=C/C`, raw);
`%2F` refused by CIR (404); "zzqqxx" in text answered by a 500 "Page not found". The fixtures in
`Tests/Fixtures/` are those answers. A call took 0.5 to 1.4 s; an entity eight calls, about 6 s.

Again on 2026-10-27: caffeine by its name (read by CIR's OPSIN; three CAS numbers, three names),
`C/C=C/C` as a SMILES, "zzqqxx" null (the example above).

Not verified: OPSIN as a fallback against a name CIR really failed to read - every systematic name
tried that day was read by CIR's own OPSIN (the test makes CIR fail on one to follow the path, with
both services' real answers); a rate limit (none published, none met); CIR's other
representations (SDF, other identifiers), not asked.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the NCI/CADD Group (National Cancer Institute), the University of Cambridge or the
EMBL-EBI. CIR publishes no rate nor terms beyond its disclaimer; OPSIN is open source (MIT) and to
be cited (Lowe, D. M. et al., J. Chem. Inf. Model. 51, 739-753, 2011), as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-cactus#terms-of-use) says. Whoever
runs the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-cactus#responsibility) section.
