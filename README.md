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
Date: 2026-01-28 01:30:41

Path types (Unix-only, no Windows/UNC):
  - 50% clean Unix paths (already normalized)
  - 33% Unix paths with multiple slashes (need normalization)
  - 17% PHP stream wrappers

Variants being tested:
  Original  = Current WordPress (regex-based, no cache) - BASELINE
  Simple    = Single array cache, stores key=>value (fastest, most memory)
  Compact   = Simple + stores true when unchanged (original compact approach)
  Best      = Recommended: compact with single-lookup optimization
  Segmented = Hot/warm two-tier cache (bounded memory)
  Dennis    = Regex-free implementation (no cache)
----------------------------------------------------------------------------------------------------

Running benchmarks...

----------------------------------------------------------------------------------------------------
RESULTS (times in milliseconds)
----------------------------------------------------------------------------------------------------

Paths   Unique  Original  Simple    Compact   Best      Segmented  Dennis  
------------------------------------------------------------------------------
1000    334     0.345     0.121     0.130     0.124     0.312      0.369   
2000    667     0.585     0.209     0.237     0.240     0.664      0.741   
4000    1334    1.159     0.408     0.463     0.454     1.381      1.470   

----------------------------------------------------------------------------------------------------
SPEED COMPARISON VS ORIGINAL (positive = faster)
----------------------------------------------------------------------------------------------------

Paths   Simple        Compact       Best          Segmented     Dennis      
------------------------------------------------------------------------------
1000    +64.8%        +62.4%        +63.9%        +9.4%        -7.0%
2000    +64.4%        +59.5%        +59.0%        -13.5%        -26.5%
4000    +64.8%        +60.1%        +60.8%        -19.2%        -26.8%

----------------------------------------------------------------------------------------------------
CACHE MEMORY USAGE
----------------------------------------------------------------------------------------------------

Paths   Unique  Simple          Best/Compact    Segmented           
----------------------------------------------------------------------
1000    334     74.03 KB        59.00 KB        152 @ 33.75 KB      
2000    667     147.93 KB       117.90 KB       139 @ 31.31 KB      
4000    1334    296.68 KB       236.33 KB       148 @ 33.18 KB      

----------------------------------------------------------------------------------------------------
FINAL COMPARISON (at 4000 paths)
----------------------------------------------------------------------------------------------------

Variant     Time (ms)     Memory          vs Original       
----------------------------------------------------------
Original    1.159         N/A (no cache)  baseline          
Simple      0.408         296.68 KB       +64.8% faster
Compact     0.463         236.33 KB       +60.1% faster
Best        0.454         236.33 KB       +60.8% faster
Segmented   1.381         33.18 KB        -19.2%
Dennis      1.470         N/A (no cache)  -26.8%

CONCLUSION:
----------------------------------------------------------
  Simple:    Maximum speed (+65%), highest memory (296.68 KB)
  Best:      Balanced (+61% speed, 20% less memory)
  Segmented: Bounded memory (33.18 KB), max 100 entries/tier
  Dennis:    No cache (-27%), no memory overhead

  The 'Best' implementation trades ~11% speed for ~20% memory savings.
  Segmented provides hard memory bounds but loses speed due to cache evictions.

----------------------------------------------------------------------------------------------------
Benchmark complete.
----------------------------------------------------------------------------------------------------
```
