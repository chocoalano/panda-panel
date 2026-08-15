<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Clusters;

use BackedEnum;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Enums\ClusterPosition;

/**
 * A cluster with a resource and a page in it, for the routing and navigation
 * a cluster is responsible for.
 */
final class OperationsCluster extends Cluster
{
    protected static ?string $title = 'Operations';

    protected static ?string $slug = 'ops';

    protected static ?string $navigationIcon = 'settings';

    protected static ?string $activeNavigationIcon = 'shield';

    protected static string|BackedEnum|null $navigationGroup = 'System';

    protected static int $navigationSort = 90;

    protected static ClusterPosition $position = ClusterPosition::Header;
}
