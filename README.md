\# Laravel Migration Preflight



A Laravel package that validates migrations before execution to prevent schema-related failures.



\## Installation



composer require samody/laravel-migration-preflight



\## Usage



php artisan migrate:preflight



\## Features



\- Detect missing foreign key tables

\- Prevent invalid migration order

\- Catch schema issues before execution

\- Improve deployment safety



\## Example Output



Preflight FAILED:



create\_orders\_table

&#x20;- Missing referenced table 'users' for 'user\_id'

