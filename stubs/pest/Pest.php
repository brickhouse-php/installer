<?php

pest()
    ->extend(\App\Tests\TestCase::class)
    ->in('Unit', 'Feature');
