<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Clusters;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Pages\Page;

/**
 * A page inside the same cluster, so a cluster is proven to gather both.
 */
final class ClusteredReportPage extends Page
{
    protected static ?string $title = 'Throughput';

    protected static ?string $slug = 'throughput';

    protected static int $navigationSort = 20;

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = OperationsCluster::class;
}
