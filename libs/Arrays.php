<?php
namespace TinyQueries;

/**
 * Arrays
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Arrays
{
    /**
     * Checks if an array is an assocative array
     *
     * @param mixed $array
     * @return bool
     */
    public static function isAssoc(&$array)
    {
        return (is_array($array)
            && array_keys($array) !== range(0, count($array) - 1))
            ? true
            : false;
    }

    /**
     * Converts a nummerical array to an associative array, based on the given key
     *
     * @param array $rows
     * @param string $key;
     * @return array
     */
    public static function toAssoc($rows, $key)
    {
        $assocArray = array();

        // Check if key is present
        if (count($rows)>0
            && !array_key_exists($key, $rows[0])) {
            throw new \Exception("Arrays::toAssoc: key '$key' not present in rows'");
        }

        // Build the new array
        foreach ($rows as $row) {
            $assocArray[ $row[ $key ] ] = $row;
        }

        return $assocArray;
    }

    /**
     * Groups the rows on the given key
     *
     * @param array $rows
     * @param string $key
     * @param boolean $deleteKey If true, removes the key-column from the result
     * @return array
     */
    public static function groupBy($rows, $key, $deleteKey = false)
    {
        $assocArray = array();

        // Check if key is present
        if (count($rows)>0
            && !array_key_exists($key, $rows[0])) {
            throw new \Exception("Arrays::groupBy: key '$key' not present in rows'");
        }

        // Build the new array
        foreach ($rows as $row) {
            $keyValue = $row[ $key ];

            if ($deleteKey) {
                unset($row[ $key ]);
            }

            // Create empty array if keyValue is not yet present
            if (!array_key_exists($keyValue, $assocArray)) {
                $assocArray[ $keyValue ] = array();
            }

            // Push row
            $assocArray[ $keyValue ][] = $row;
        }

        return $assocArray;
    }

    /**
     * Converts a associative array to an numerical array
     *
     * @param array $rows
     * @return array
     */
    public static function toIndexed($assoc)
    {
        $indexBased = array();
        foreach ($assoc as $_dummy => $value) {
            $indexBased[] = $value;
        }

        return $indexBased;
    }

    /**
     * Merges two numerical arrays based on an the order of a common field (which is denoted by $orderBy)
     *
     * @param array &$array Array which will be modified by adding element of the next array:
     * @param array $arrayToAdd
     * @param string $orderBy (optional) Name of the field which should be used for ordering the merged result
     * @param string $orderType (optional) 'asc' or 'desc'; default is 'asc'
     */
    public static function mergeArrays(&$array, $arrayToAdd, $orderBy = null, $orderType = 'asc')
    {
        if (!$orderBy) {
            // Simply add the array
            $array = array_merge($array, $arrayToAdd);
            return;
        }

        /// TODO this algorithm can be made much faster if it can be assumed that each query returns sorted output
        foreach ($arrayToAdd as $item) {
            // find position in the objects-array where the item should be added

            $k = 0;

            while (
                $k < count($array) &&
                (
                    ($orderType == 'asc' 	&& $array[$k][ $orderBy ] < $item[ $orderBy ]) ||
                    ($orderType == 'desc' 	&& $array[$k][ $orderBy ] > $item[ $orderBy ])
                )
            ) {
                $k++;
            }

            if ($k < count($array)) {
                array_splice($array, $k, 0, array( $item ));
            } else {
                $array[] = $item;
            }
        }
    }

    /**
     * @param array $input
     * @param int $offset
     * @param int $length
     * @param array $replacement
     */
    public static function spliceAssoc(&$input, $offset, $length = null, $replacement = array())
    {
        if (is_null($length)) {
            $length = count($input);
        }

        $replacement = (array) $replacement;
        $key_indices = array_flip(array_keys($input));
        if (isset($input[$offset]) && is_string($offset)) {
            $offset = $key_indices[$offset];
        }
        if (isset($input[$length]) && is_string($length)) {
            $length = $key_indices[$length] - $offset;
        }

        $input = array_slice($input, 0, $offset, true)
            + $replacement
            + array_slice($input, $offset + $length, null, true);
    }

    /**
     * Merges the $key - $value pair into $assoc
     * Simple case is that $value is just a string or int
     * But it can also be the case that $value is an assoc and that $assoc[$key] is also an assoc
     *
     * @param assoc $assoc
     * @param string $key
     * @param string|int|assoc $value
     */
    public static function mergeField(&$assoc, $key, $value)
    {
        // Simple case; key is not present
        if (!array_key_exists($key, $assoc)) {
            // Create entry
            $assoc[$key] = $value;
            return;
        }

        // Special cases
        if (is_null($value)) {
            return;
        }

        if (is_null($assoc[$key])) {
            $assoc[$key] = $value;
            return;
        }

        $a1 = self::isAssoc($assoc[$key]);
        $a2 = self::isAssoc($value);

        if (!$a1 && !$a2) {
            // Overwrite entry
            $assoc[$key] = $value;
            return;
        }

        if ($a1 && $a2) {
            // Do recursive call
            foreach ($value as $subkey => $subvalue) {
                self::mergeField($assoc[$key], $subkey, $subvalue);
            }

            return;
        }

        throw new \Exception("Cannot merge field '$key' - types are different");
    }

    /**
     * @param array $array
     * @param array $arrayToAdd
     * @param string $orderBy
     * @param string $orderType
     */
    public static function mergeAssocs(&$array, $arrayToAdd, $orderBy = null, $orderType = 'asc')
    {
        foreach ($arrayToAdd as $idToAdd => $itemToAdd) {
            // element already exists
            if (array_key_exists($idToAdd, $array)) {
                // copy and/or add the fields of the array-element
                foreach (array_keys($itemToAdd) as $field) {
                    self::mergeField($array[ $idToAdd ], $field, $itemToAdd[ $field ]);
                }
                // element does not exist and elements should be ordered
            } elseif ($orderBy) {
                // find position in the array where the item should be added
                $k = 0;

                foreach ($array as $id => $item) {
                    if (
                        ($orderType == 'asc' 	&& $item[ $orderBy ] < $itemToAdd[ $orderBy ]) ||
                        ($orderType == 'desc' 	&& $item[ $orderBy ] > $itemToAdd[ $orderBy ])
                    ) {
                        $k++;
                    } else {
                        break;
                    }
                }

                if ($k < count($array)) {
                    self::spliceAssoc($array, $k, 0, array( $idToAdd => $itemToAdd ));
                } else {
                    $array[ $idToAdd  ] = $itemToAdd;
                }
                // element does not exist and elements are not ordered
            } else {
                // just add the new field
                $array[ $idToAdd  ] = $itemToAdd;
            }
        }
    }

    /**
     * @param mixed $d
     * @return array
     */
    public static function objectToArray($d)
    {
        if (is_object($d)) {
            // Gets the properties of the given object
            // with get_object_vars function
            $d = get_object_vars($d);
        }

        if (is_array($d)) {
            /*
            * Return array converted to object
            * Using __FUNCTION__ (Magic constant)
            * for recursive call
            */
            return array_map('self::objectToArray', $d);
        } else {
            // Return array
            return $d;
        }
    }

    /**
     * Recursive function to remove structures like { a: null, b: null, c: { d: null, e: null } }
     *
     * @param assoc $fields
     */
    public static function reduceNulls(&$fields)
    {
        if (!self::isAssoc($fields)
            && !is_object($fields)) {
            return;
        }

        // First do recursive call
        foreach ($fields as $key => $value) {
            (is_object($fields))
                ? self::reduceNulls($fields->$key)
                : self::reduceNulls($fields[$key]);
        }

        // Check if there are non null values
        foreach ($fields as $key => $value) {
            if (!is_null($value)) {
                return;
            }
        }

        // If not, then reduce the $fields to null
        $fields = null;
    }

    /**
     * Transforms rows to columns
     *
     * @param array $array Array of associative arrays
     * @param string $key
     * @param string $name
     * @param string $value
     * @return array
     */
    public static function rows2columns(&$array, $key, $name, $value)
    {
        $trans = array();

        foreach ($array as $row) {
            $id = "id" . $row[ $key ];

            if (!array_key_exists($id, $trans)) {
                $trans[ $id ] = array(
                    $key => $row[ $key ]
                );
            }

            $trans[ $id ][ $row[ $name ] ] = $row[ $value ];
        }

        return self::toIndexed($trans);
    }

    /**
     * Makes an array of $any if it is not yet an array
     *
     * @param mixed $any
     * @return array
     */
    public static function toArray($any)
    {
        if (is_array($any)) {
            return $any;
        }

        if (is_null($any)) {
            return array();
        }

        return array($any);
    }
}
