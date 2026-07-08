<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tool;

use plugin\saimulti\app\model\tool\AreaCode;
use plugin\saimulti\basic\BaseLogic;

/**
 * 中国区域编码逻辑层
 */
class AreaCodeLogic extends BaseLogic
{
    private const MIN_LEVEL = 1;
    private const MAX_LEVEL = 5;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new AreaCode();
        parent::__construct();
    }

    /**
     * Get area nodes for Element Plus cascader lazy loading.
     */
    public function cascaderNodes(int|string|null $pcode = null, int $maxLevel = self::MAX_LEVEL): array
    {
        $maxLevel = $this->normalizeLevel($maxLevel);
        $query = $this->model->where('status', 1);

        if ($pcode === null || $pcode === '') {
            $query->where('level', self::MIN_LEVEL);
        } else {
            $query->where('pcode', $pcode)->where('level', '<=', $maxLevel);
        }

        $rows = $query
            ->field('area_code, name, level, pcode')
            ->order('area_code', 'asc')
            ->select()
            ->toArray();

        if (empty($rows)) {
            return [];
        }

        $childPcodes = $this->getChildPcodeMap($rows, $maxLevel);

        return array_map(function ($row) use ($childPcodes, $maxLevel) {
            $areaCode = $row['area_code'];
            $level = (int) $row['level'];

            return [
                'label' => $row['name'],
                'value' => $areaCode,
                'level' => $level,
                'pcode' => $row['pcode'],
                'leaf' => $level >= $maxLevel || !isset($childPcodes[(string) $areaCode]),
            ];
        }, $rows);
    }

    /**
     * Get the selected node path from province to the requested area code.
     */
    public function cascaderPath(int|string $areaCode, int $maxLevel = self::MAX_LEVEL): array
    {
        $maxLevel = $this->normalizeLevel($maxLevel);
        $path = [];
        $currentCode = $areaCode;

        while ($currentCode !== null && $currentCode !== '' && count($path) < self::MAX_LEVEL) {
            $row = $this->model
                ->where('status', 1)
                ->where('area_code', $currentCode)
                ->field('area_code, name, level, pcode')
                ->findOrEmpty();

            if ($row->isEmpty()) {
                break;
            }

            $data = $row->toArray();
            $path[] = $data;
            $currentCode = $data['pcode'];
        }

        $path = array_reverse($path);
        $path = array_values(array_filter($path, function ($row) use ($maxLevel) {
            return (int) $row['level'] <= $maxLevel;
        }));

        return array_map(function ($row) use ($maxLevel) {
            $level = (int) $row['level'];

            return [
                'label' => $row['name'],
                'value' => $row['area_code'],
                'level' => $level,
                'pcode' => $row['pcode'],
                'leaf' => $level >= $maxLevel,
            ];
        }, $path);
    }

    /**
     * Clamp selected depth to the supported area-code level range.
     */
    private function normalizeLevel(int $level): int
    {
        return max(self::MIN_LEVEL, min(self::MAX_LEVEL, $level));
    }

    /**
     * Build a set of parent codes that still have enabled children.
     */
    private function getChildPcodeMap(array $rows, int $maxLevel): array
    {
        $needCheckCodes = [];
        foreach ($rows as $row) {
            if ((int) $row['level'] < $maxLevel) {
                $needCheckCodes[] = $row['area_code'];
            }
        }

        if (empty($needCheckCodes)) {
            return [];
        }

        $pcodes = $this->model
            ->where('status', 1)
            ->where('pcode', 'in', $needCheckCodes)
            ->where('level', '<=', $maxLevel)
            ->column('pcode');

        $map = [];
        foreach ($pcodes as $pcode) {
            $map[(string) $pcode] = true;
        }

        return $map;
    }
}
