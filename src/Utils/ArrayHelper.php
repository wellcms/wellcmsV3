<?php

declare(strict_types=1);

/**
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Utils;

/**
 * ArrayHelper 类提供对数组的各种处理方法
 */
class ArrayHelper
{
    /* $users = [
        ['id' => 101, 'name' => 'Alice'],
        ['id' => 102, 'name' => 'Bob'],
        ['id' => 103, 'name' => 'Charlie']
    ]; */
    // 示例用法
    //$result = findIdByFast($users, 'Charlie'); // 返回 103
    public static function findIdByFast(array $arr, string $name, string $key, string $returnKey): ?int
    {
        // 提取所有name字段形成新数组
        $names = array_column($arr, $key, $returnKey);
        // 在$names数组中严格搜索$name，返回其对应的键（索引）
        $foundKey = array_search($name, $names, true);

        // 如果找到且原数组存在该键，返回对应的id
        if ($foundKey !== false && isset($arr[$foundKey][$returnKey])) {
            return (int)$arr[$foundKey][$returnKey];
        }

        return null;
    }

    /**
     * 对二维数组排序 arrlist_multisort() 改为 multiSortKey()
     *
     * @param array   $arrlist  待排序的二维数组
     * @param string  $col      排序列键名
     * @param bool    $asc      true升序 / false降序
     * @param string  $key      索引键
     *
     * @return array  排序后的数组
     */
    public static function multiSortKey(array $arrlist = [], string $col = '', bool $asc = true, string $key = '')
    {
        if (empty($arrlist) || !$col) {
            return [];
        }

        $colarr = array_column($arrlist, $col);
        $asc = $asc ? SORT_ASC : SORT_DESC;
        array_multisort($colarr, $asc, $arrlist);
        unset($colarr);

        $key and $arrlist = self::changeKey($arrlist, $key);

        return $arrlist;
    }

    /**
     * 更改二维数组key，值替换键
     *
     * @param array   $arrlist  待处理的二维数组
     * @param string  $key      替换后的键
     *
     * @return array  处理后的数组
     */
    public static function changeKey(array $arrlist = [], string $key = '')
    {
        if (empty($arrlist) || !$key) {
            return $arrlist;
        }

        $arr = [];
        foreach ($arrlist as $v) {
            $arr[$v[$key]] = $v;
        }

        return $arr;
    }

    /**
     * 二维数组分页，对排序的整个数组分页获取数据
     *
     * @param array  $arrlist   待分页的数组
     * @param int    $page      当前页
     * @param int    $pagesize  每页数量
     *
     * @return array  分页后的数组
     */
    public static function pagination(array $arrlist = [], int $page = 1, int $pagesize = 20)
    {
        if (empty($arrlist)) {
            return [];
        }

        $page = max(1, $page);
        $pagesize = max(1, $pagesize);
        $offset = ($page - 1) * $pagesize;

        return array_slice($arrlist, $offset, $pagesize, true);
    }

    /**
     * 二维数组整理一维关联数组
     *
     * @param array   $arr     待整理的数组
     * @param string  $col     排序列
     * @param string  $key     关联key=>value
     * @param string  $value   关联的值
     *
     * @return array  整理后的数组
     */
    public static function rankKey(array $arr = [], string $col = '', string $key = '', ?string $value = null)
    {
        if (!empty($arr) && $col && $key && $value) {
            $arr = self::multiSort($arr, $col, FALSE);
            $arr = self::arraylistValues($arr, $key, $value);
        }

        return $arr;
    }

    /**
     * 移除二维数组中的重复的值，并返回结果数组
     *
     * @param array  $array2D    待处理的二维数组
     * @param bool   $stkeep     true保留一级数组键 / false不保留一级数组键
     * @param bool   $ndformat   true保留二级数组键 / false不保留二级数组键
     *
     * @return array  处理后的数组
     */
    public static function unique(array $array2D, bool $stkeep = FALSE, bool $ndformat = TRUE)
    {
        // 判断是否保留一级数组键 (一级数组键可以为非数字)
        $starr = $stkeep ? array_keys($array2D) : [];
        // 判断是否保留二级数组键 (所有二级数组键必须相同)
        $ndarr = $ndformat ? array_keys(end($array2D)) : [];
        // 降维，也可以用implode，将一维数组转换为用逗号连接的字符串
        $temp = [];

        foreach ($array2D as $v) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            $temp[] = $v;
        }

        // 去掉重复的字符串
        $temp = array_unique($temp);
        // 再将拆开的数组重新组装
        $output = [];

        foreach ($temp as $k => $v) {
            if ($stkeep) {
                $k = $starr[$k];
            }
            if ($ndformat) {
                $temparr = json_decode($v, true);
                foreach ($temparr as $ndkey => $ndval) {
                    $output[$k][$ndarr[$ndkey]] = $ndval;
                }
            } else {
                $output[$k] = json_decode($v, true);
            }
        }

        return $output;
    }

    // 合并二维数组 如重复 值以第一个数组值为准
    public static function merge(array $array1 = [], array $array2 = [], string $key = '')
    {
        if (empty($array1) || empty($array2)) {
            return [];
        }

        $arr = [];
        foreach ($array1 as $k => $v) {
            isset($v[$key]) ? $arr[$v[$key]] = array_merge($v, $array2[$k]) : $arr[] = array_merge($v, $array2[$k]);
        }

        return $arr;
    }

    /**
     * 对二维数组排序，两个数组必须有一个相同的键值
     *
     * @param array  $array1  需要排序数组
     * @param array  $array2  按照该数组key排序
     * @param string $key     排序的键
     *
     * @return array  排序后的数组
     */
    public static function sortKey(array $array1 = [], array $array2 = [], string $key = '')
    {
        if (empty($array1) || empty($array2)) {
            return [];
        }

        $arr = [];
        foreach ($array2 as $v) {
            if (isset($v[$key]) && $v[$key] == $array1[$v[$key]][$key]) {
                $arr[$v[$key]] = $array1[$v[$key]];
            } else {
                $arr[] = $v;
            }
        }

        return $arr;
    }

    /**
     * 根据键查找数组中的值 $arr[$key]
     *
     * @param array   $arr      待查找的数组
     * @param string  $key      键值
     * @param string  $default  默认值
     *
     * @return mixed  查找到的值或默认值
     */
    public static function value(array $arr = [], string $key = '', string $default = '')
    {
        return isset($arr[$key]) ? $arr[$key] : $default;
    }

    /**
     * 多维数组中值为预定义的字符串编码为 HTML 实体 &"<>' 编码为 &amp;&quot;&lt;&gt;&#039;
     *
     * @param mixed &$var  待处理的变量
     * @param int    $type 0:不编码双引号和单引号 / 1:仅编码双引号 / 2:编码双引号和单引号(严格模式)
     *
     * @return mixed 处理后的变量
     */
    public static function htmlspecialchars(&$var, int $type = 2)
    {
        if (is_array($var)) {
            foreach ($var as &$v) {
                self::htmlspecialchars($v, $type);
            }
        } else {
            if (1 == $type) {
                $flags = ENT_COMPAT;
            } elseif (2 == $type) {
                $flags = ENT_QUOTES;
            } else {
                $flags = ENT_NOQUOTES;
            }
            $var = \htmlspecialchars((string)($var ?? ''), $flags);
        }
        return $var;
    }

    /*
        $data = [];
        $data[] = array('volume' => 67, 'edition' => 2);
        $data[] = array('volume' => 86, 'edition' => 1);
        $data[] = array('volume' => 85, 'edition' => 6);
        $data[] = array('volume' => 98, 'edition' => 2);
        $data[] = array('volume' => 86, 'edition' => 6);
        $data[] = array('volume' => 67, 'edition' => 7);
        multisort($data, 'edition', TRUE);
    */
    /**
     * 对多维数组键排序
     *
     * @param array  $arrlist  待排序的二维数组
     * @param string $col      排序列键名
     * @param bool   $asc      SORT_ASC - 默认:按升序排列 (A-Z) / SORT_DESC - 按降序排列 (Z-A)
     *
     * @return array  排序后的数组
     */
    public static function multiSort(array $arrlist = [], string $col = '', bool $asc = TRUE)
    {
        if (empty($arrlist) || !$col) {
            return [];
        }

        $colarr  = array_column($arrlist, $col);
        $asc = $asc ? SORT_ASC : SORT_DESC;
        array_multisort($colarr, $asc, $arrlist);

        return $arrlist;
    }

    /**
     * 取一维或二维数组指定数量的数据并按之前排序
     *
     * @param array  $arrlist  数组
     * @param int    $start    起始值
     * @param int    $length   数量长度
     *
     * @return array  切片后的数组
     */
    public static function assocSlice(array $arrlist = [], int $start = 0, int $length = 0)
    {
        if (isset($arrlist[0])) {
            return array_slice($arrlist, $start, $length);
        }

        $keys = array_keys($arrlist); // 取key
        $keys2 = array_slice($keys, $start, $length); // 取指定数量key
        $retlist = [];
        foreach ($keys2 as $key) {
            $retlist[$key] = $arrlist[$key];
        }

        return $retlist;
    }

    /**
     * 对数组进行查找，排序，筛选，支持多种条件排序 arrlist_cond_orderby()
     *
     * @param array  $arrlist   数组
     * @param array  $condition      条件 []
     * @param array  $orderby   array('id' => 1)按升序排列 / array('id' => -1)按降序排列
     * @param int    $page      当前页
     * @param int    $pagesize  每页数量
     *
     * @return array  处理后的数组
     */
    public static function arrayListConditionOrderBy(array $arrlist = [], array $condition = [], array $orderby = [], int $page = 1, int $pagesize = 20)
    {
        $resultarr = [];
        if (empty($arrlist)) {
            return $arrlist;
        }

        // 根据条件，筛选结果
        if ($condition) {
            foreach ($arrlist as $key => $val) {
                $ok = TRUE;
                foreach ($condition as $k => $v) {
                    if (!isset($val[$k])) {
                        $ok = FALSE;
                        break;
                    }

                    if (!is_array($v)) {
                        if ($val[$k] != $v) {
                            $ok = FALSE;
                            break;
                        }
                    } else {
                        foreach ($v as $k3 => $v3) {
                            if (
                                ($k3 == '>' && $val[$k] <= $v3) ||
                                ($k3 == '<' && $val[$k] >= $v3) ||
                                ($k3 == '>=' && $val[$k] < $v3) ||
                                ($k3 == '<=' && $val[$k] > $v3) ||
                                ($k3 == '==' && $val[$k] != $v3) ||
                                ($k3 == 'LIKE' && stripos($val[$k], $v3) === FALSE)
                            ) {
                                $ok = FALSE;
                                break 2;
                            }
                        }
                    }
                }

                if ($ok) {
                    $resultarr[$key] = $val;
                }
            }
        } else {
            $resultarr = $arrlist;
        }

        if ($orderby) {
            $k = key($orderby);
            $v = current($orderby);

            $resultarr = self::multiSort($resultarr, $k, $v == 1);
        }

        $start = ($page - 1) * $pagesize;

        $resultarr = self::assocSlice($resultarr, $start, $pagesize);

        return $resultarr;
    }

    /**
     * 从二维数组中取出 key=>value 格式的一维数组 arrayListValues()
     *
     * @param array   $arrlist     二维数组
     * @param string  $index_key   需要返回值的列。可以是索引数组的列的整数索引，或者是关联数组的列的字符串键值。该参数也可以是 NULL，此时将返回整个数组（配合index_key 参数来重置数组键的时候，非常管用）
     * @param string  $column_key  作为返回数组的索引/键的列
     * @param string  $index_key         前缀
     *
     * @return array  生成的一维数组
     */
    public static function arrayListValues(array $arrlist = [], string $column_key = '', ?string $index_key = null)
    {
        if (empty($arrlist)) {
            return [];
        }

        return array_column($arrlist, $column_key, $index_key);
    }

    /**
     * 从一个二维数组中对某一列求和
     *
     * @param array   $arrlist  二维数组
     * @param string  $key      求和键的列
     *
     * @return int  求和结果
     */
    public static function sum(array $arrlist = [], string $key = '')
    {
        if (empty($arrlist) || !$key) {
            return 0;
        }

        return array_sum(array_column($arrlist, $key));
    }

    // 从一个二维数组中对某一列求最大值
    public static function max(array $arrlist = [], string $key = '')
    {
        if (empty($arrlist)) {
            return 0;
        }

        return max(array_column($arrlist, $key));
    }

    // 从一个二维数组中对某一列求最小值
    public static function min(array $arrlist = [], string $key = '')
    {
        if (empty($arrlist)) {
            return 0;
        }

        return min(array_column($arrlist, $key));
    }


    // 将 key 更换为某一列的值，在对多维数组排序后，数字key会丢失，需要此函数 arrlist_change_key()
    public static function arrayListChangeKey(array $arrlist = [], string $col = '')
    {
        if (empty($arrlist) || !$col) {
            return [];
        }

        return array_column($arrlist, null, $col);
    }

    // 将一个二维数组合并为一个数组
    public static function arrayForeach(array $arrlist = [], string $column = '')
    {
        if (empty($arrlist)) {
            return [];
        }

        $arr = [];
        foreach ($arrlist as $val) {
            $arr[] = $val[$column];
        }

        return $arr;
    }

    /**
     * 将数组键值转化成小写
     *
     * @param array  $arrlist  待处理数组
     *
     * @return array  处理后数组
     */
    public static function arrayListKeyLower(array $arrlist = [])
    {
        if (empty($arrlist)) {
            return [];
        }

        $newarr = [];
        foreach ($arrlist as $k => $v) {
            $k = strtolower($k);
            $newarr[$k] = $v;
        }

        return $newarr;
    }

    /**
     * 将数组键值转化成大写
     *
     * @param array  $arrlist  待处理数组
     *
     * @return array  处理后数组
     */
    public static function arrayListKeyUpper(array $arrlist = [])
    {
        if (empty($arrlist)) {
            return [];
        }

        $newarr = [];
        foreach ($arrlist as $k => $v) {
            $k = strtoupper($k);
            $newarr[$k] = $v;
        }

        return $newarr;
    }

    /**
     * 通过数组列进行排序
     *
     * @param array   $arrlist   待排序数组
     * @param string  $column    用于排序的数组列
     * @param int     $order     排序方式：SORT_ASC - 默认按升序排列 / SORT_DESC - 按降序排列
     * @param int     $type      排序类型：SORT_REGULAR - 默认按照常规方法比较 / SORT_NUMERIC - 按照数字形式比较 / SORT_STRING - 按照字符串形式比较
     *
     * @return array  排序后的数组
     */
    public static function arraySort(array $arrlist = [], string $column = '', int $order = SORT_ASC, int $type = SORT_REGULAR)
    {
        if (empty($arrlist) || !$column) {
            return [];
        }

        $arrColumn = array_column($arrlist, $column);
        array_multisort($arrColumn, $order, $type, $arrlist);

        return $arrlist;
    }

    /**
     * 保留指定的键值 arrlist_keep_keys()
     * @param array $arrlist
     * @param array $keys
     * @return array
     */
    /*
    $grouplist = [
        ['group_id' => 0, 'name' => '游客组'],
        ['group_id' => 1, 'name' => '管理员组'],
        ['group_id' => 2, 'name' => '超级版主组']
    ];

    $grouparr = keepKeys($grouplist, ['name']);

    Array
    (
        [0] => Array
            (
                [name] => 游客组
            )
        [1] => Array
            (
                [name] => 管理员组
            )
        [2] => Array
            (
                [name] => 超级版主组
            )
    )
    */
    public static function keepKeys(array $arrlist = [], array $keys = []): array
    {
        if (empty($keys) || empty($arrlist)) return $arrlist;

        $keyMap = array_flip((array)$keys);
        foreach ($arrlist as &$v) {
            $v = array_intersect_key($v, $keyMap);
            // 补齐缺失的键为 null，保持结构一致性
            foreach ($keys as $key) {
                if (!isset($v[$key])) $v[$key] = null;
            }
        }
        return $arrlist;
    }

    // 根据某一列的值进行组块
    public static function chunk(array $arrlist = [], string $key = '')
    {
        $r = [];
        if (empty($arrlist) || !$key) return $r;
        foreach ($arrlist as $arr) {
            !isset($r[$arr[$key]]) and $r[$arr[$key]] = [];
            $r[$arr[$key]][] = $arr;
        }
        return $r;
    }
}
