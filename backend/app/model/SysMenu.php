<?php
namespace app\model;

use think\Model;

class SysMenu extends Model
{
    protected $name = 'sys_menu';
    protected $autoWriteTimestamp = true;
    protected $createTime = false;
    protected $updateTime = false;

    public function children()
    {
        return $this->hasMany(SysMenu::class, 'parent_id')->order('sort');
    }

    public static function buildTree($list, $parentId = 0): array
    {
        $tree = [];
        foreach ($list as $item) {
            if ((int)$item['parent_id'] === (int)$parentId) {
                $children = self::buildTree($list, $item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }

    /**
     * 移除空分组（v2.69）
     * 目录节点（无 path / component）若没有任何可见子项，则整组不返回，
     * 避免侧边栏出现「点了没反应」的空目录。
     */
    public static function pruneEmptyGroups(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $children = self::pruneEmptyGroups($node['children'] ?? []);
            $isGroup  = empty($node['path']) && empty($node['component']);
            if ($isGroup && !$children) {
                continue;
            }
            if ($children) {
                $node['children'] = $children;
            } else {
                unset($node['children']);
            }
            $out[] = $node;
        }
        return $out;
    }
}
