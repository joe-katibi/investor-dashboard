<?php

namespace App\Services\Mobility;

use App\Models\ImpactRule;
use App\Models\Priority;
use App\Models\TransmissionNode;
use App\Services\Outages\PriorityResolver;

/**
 * What a 4G fault takes down, and how severe that makes it.
 *
 * A selected transmission node takes everything below it with it: an RSG its ASGs, an ASG its CSG
 * rings, a CSG the SSGs of its ring, and every SSG the eNodeB it carries. eNodeBs selected directly
 * (a site fault that does not involve its transmission node) are added to those.
 */
class MobilityImpact
{
    public function __construct(private readonly PriorityResolver $priorities) {}

    /**
     * @param  array<int>  $siteIds  eNodeB sites selected on the ticket
     * @param  array<int>  $nodeIds  transmission nodes selected on the ticket
     * @return array{enodebs: int, cells: int, ssg: int, csg: int, asg: int, rsg: int, customers: int}
     */
    public function counts(array $siteIds, array $nodeIds, int $cells = 0, int $customers = 0): array
    {
        $nodes = TransmissionNode::withDescendants($nodeIds);
        $byType = array_count_values($nodes);

        $carried = $nodes === [] ? [] : TransmissionNode::query()
            ->whereIn('id', array_keys($nodes))->whereNotNull('enodeb_id')->pluck('enodeb_id')->all();

        $sites = array_unique(array_merge(array_map('intval', array_filter($siteIds)), array_map('intval', $carried)));

        return [
            'enodebs' => count($sites),
            'cells' => max(0, $cells),
            'ssg' => $byType[TransmissionNode::SSG] ?? 0,
            'csg' => $byType[TransmissionNode::CSG] ?? 0,
            'asg' => $byType[TransmissionNode::ASG] ?? 0,
            'rsg' => $byType[TransmissionNode::RSG] ?? 0,
            'customers' => max(0, $customers),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{priority: ?Priority, rule: ?ImpactRule}
     */
    public function severity(int $verticalId, ?int $outageTypeId, array $counts): array
    {
        return $this->priorities->forImpact($verticalId, $outageTypeId, $counts);
    }

    /** "5 eNodeBs · 2 SSGs · 1 CSG" */
    public static function describe(array $counts): string
    {
        $names = ['enodebs' => 'eNodeB', 'cells' => 'cell', 'ssg' => 'SSG', 'csg' => 'CSG', 'asg' => 'ASG', 'rsg' => 'RSG'];
        $parts = [];

        foreach ($names as $key => $name) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = $counts[$key].' '.$name.($counts[$key] === 1 ? '' : 's');
            }
        }

        return implode(' · ', $parts);
    }
}
