# omnilab/cactus

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
InChIKey into PubChem - walked on 2026-10-16 (see `.samples/live.php`). CIR is the entry point for
what has no id yet: a name, a SMILES, a drawn structure's InChI.

## Verified, and not

Against the real services on 2026-10-16: caffeine by its name (read by CIR's OPSIN), by its CAS
number (read as a name by CIR's tables) and by InChIKey; "CCO" (read as a SMILES: ethanol, though
CIR also reads it as another compound's name); aspirin by its InChIKey; an InChI whose structure CIR
holds no name for (its formula and weight computed; no IUPAC name, CAS or names: 404s); "zzqqxx"
(an empty XML answer; OPSIN's 404); OPSIN's answer for
"2-methyl-3-(2-methylcyclopropyl)pentanedioic acid"; a SMILES with slashes (`C/C=C/C`, raw);
`%2F` refused by CIR (404); "zzqqxx" in text answered by a 500 "Page not found". The fixtures in
`Tests/Fixtures/` are those answers. A call took 0.5 to 1.4 s; an entity eight calls, about 6 s.

Not verified: OPSIN as a fallback against a name CIR really failed to read - every systematic name
tried that day was read by CIR's own OPSIN (the test makes CIR fail on one to follow the path, with
both services' real answers); a rate limit (none published, none met); CIR's other
representations (SDF, other identifiers), not asked.
