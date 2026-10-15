# omnilab/cactus

The NCI/CADD Chemical Identifier Resolver (CIR, cactus.nci.nih.gov) for
[glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a structure from whatever names it -
a name, a CAS number, an InChIKey, a standard InChI, a SMILES - and its other identifiers and
representations: standard InChIKey (its id here), InChI, SMILES, IUPAC name, formula, molecular
weight, CAS numbers, names, a picture. A name CIR cannot read is given to OPSIN (the IUPAC name
parser), whose structure CIR then completes. No key.

```php
$cactus = (new CactusSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$caffeine = $cactus->entity('caffeine');        // or '58-08-2', 'RYYVLZVUVIJVGH-UHFFFAOYSA-N', a SMILES, an InChI
$caffeine->id;                                  // inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N
$caffeine->value('read_as');                    // IUPAC name (OPSIN): how CIR read the string
$caffeine->value('molecular_weight');           // 194.1926 g/mol
$caffeine->identifiers->values(Scheme::CAS);    // 71701-02-5, 95789-13-2, 58-08-2 (deleted numbers too)
$cactus->entity('CCO')->label;                  // ethanol: read as a SMILES
$collector->collect('cactus', 'caffeine', 1, ['pubchem']);   // then PubChem by the InChIKey
```

```yaml
omnilab:
    sources:
        cactus:
            factory: cactus
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                representations: [stdinchi, smiles, iupac_name, formula, mw, cas, names]   # one call each
                names: 20                      # synonyms kept (0: the names are not asked)
                opsin: true                    # a name CIR cannot read goes to OPSIN (false: never)
```

## Why OPSIN is here, not a package of its own

CIR already reads systematic names with OPSIN first (its resolver `name_by_opsin`: caffeine, the
λ-convention names, fused rings... all read that way on 2026-10-15). OPSIN holds no record and no
identifier - it is a parser, not a base -, so it is no other source for the `Collector`: it is the
same question (a name -> a structure) asked of the current parser when CIR's fails or is out of
date. One call to the EBI's OPSIN web service, then CIR by the InChI OPSIN gave.

## Terms of use

CIR is a service of the NCI/CADD Group (National Cancer Institute); no rate or terms of use are
published beyond its disclaimer ("we are still working on improvements" of the name and registry
number resolution); problems go to the NCI Cactus team. OPSIN is open source (MIT), by Daniel Lowe
(University of Cambridge), served by the EBI. Cite OPSIN: Lowe, D. M., Corbett, P. T.,
Murray-Rust, P., Glen, R. C., Chemical Name to Structure: OPSIN, an Open Source Solution, J. Chem.
Inf. Model. 51, 739-753 (2011).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the NCI/CADD Chemical Identifier Resolver and OPSIN (EMBL-EBI). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License (of this package's code): MIT.
