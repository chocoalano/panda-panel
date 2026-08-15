<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Clusters;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Enums\ClusterPosition;

/**
 * The same thing drawn beside the content rather than above it, so the two
 * placements are both proven.
 */
final class RightBarCluster extends Cluster
{
    protected static ?string $title = 'Reports';

    protected static ?string $slug = 'reports';

    protected static ClusterPosition $position = ClusterPosition::RightBar;
}
