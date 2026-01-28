# WordPress: wp_normalize_path()

This is a repo with resources on figuring out the right balance in optimizing
the WordPress wp_normalize_path() function.

<a href="https://core.trac.wordpress.org/ticket/64538">https://core.trac.wordpress.org/ticket/64538</a>


## Run the tests

```php
php benchmark-wp-normalize-path-unix.php
```

## Results on Apple Silicon M3

```
$ php benchmark-wp-normalize-path-unix.php 
----------------------------------------------------------------------------------------------------
Benchmark: wp_normalize_path() - Optimization Experiments (Unix-only Paths)
----------------------------------------------------------------------------------------------------
PHP Version: 8.4.7
Date: 2026-01-28 01:25:00

Path types (Unix-only, no Windows/UNC):
  - 50% clean Unix paths (already normalized)
  - 33% Unix paths with multiple slashes (need normalization)
  - 17% PHP stream wrappers

Variants being tested:
  Original = Current WordPress (regex-based, no cache) - BASELINE
  Simple   = Single array cache, stores key=>value (fastest, most memory)
  Compact  = Simple + stores true when unchanged (original compact approach)
  Best     = Recommended: compact with single-lookup optimization
  Dennis   = Regex-free implementation (no cache)
----------------------------------------------------------------------------------------------------

Running benchmarks...

----------------------------------------------------------------------------------------------------
RESULTS (times in milliseconds)
----------------------------------------------------------------------------------------------------

Paths   Unique  Original    Simple      Compact     Best        Dennis    
----------------------------------------------------------------------
1000    334     0.363       0.130       0.143       0.145       0.419     
2000    667     0.667       0.237       0.268       0.257       0.829     
4000    1334    1.296       0.454       0.505       0.501       1.569     

----------------------------------------------------------------------------------------------------
SPEED COMPARISON VS ORIGINAL (positive = faster)
----------------------------------------------------------------------------------------------------

Paths   Simple            Compact           Best              Dennis          
------------------------------------------------------------------------
1000    +64.1% faster    +60.6% faster    +59.9% faster    -15.7%
2000    +64.5% faster    +59.9% faster    +61.5% faster    -24.2%
4000    +65.0% faster    +61.1% faster    +61.3% faster    -21.0%

----------------------------------------------------------------------------------------------------
CACHE MEMORY USAGE (all experiments use compact storage)
----------------------------------------------------------------------------------------------------

Paths   Unique  Simple          Compact/Exp*    Savings       
------------------------------------------------------------
1000    334     74.03 KB        59.00 KB        20.3% less    
2000    667     147.93 KB       117.90 KB       20.3% less    
4000    1334    296.68 KB       236.33 KB       20.3% less    

----------------------------------------------------------------------------------------------------
FINAL COMPARISON (at 4000 paths)
----------------------------------------------------------------------------------------------------

Variant     Time (ms)     Memory          vs Original       
----------------------------------------------------------
Original    1.296         N/A (no cache)  baseline          
Simple      0.454         296.68 KB       +65.0% faster
Compact     0.505         236.33 KB       +61.1% faster
Best        0.501         236.33 KB       +61.3% faster
Dennis      1.569         N/A (no cache)  -21.0%

CONCLUSION:
----------------------------------------------------------
  Simple:  Maximum speed (+65%), highest memory (296.68 KB)
  Best:    Balanced (+61% speed, 20% less memory)
  Dennis:  No cache (-21%), no memory overhead

  The 'Best' implementation trades ~10% speed for ~20% memory savings.
  In Unix environments where most paths are already normalized, this is
  a good trade-off for memory-constrained systems.

----------------------------------------------------------------------------------------------------
Benchmark complete.
----------------------------------------------------------------------------------------------------
```
