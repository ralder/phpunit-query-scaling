<?php

declare(strict_types=1);

use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\Tests\Integration\TestCase;

uses(TestCase::class, QueryScalingAssertions::class)->in('Pest');
