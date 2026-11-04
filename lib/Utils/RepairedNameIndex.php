<?php

namespace ESN\Utils;

use Sabre\VObject\Component;

/**
 * Files the children of a component again under their current name.
 *
 * Component keeps its children indexed by name. Property::validate(REPAIR) renames an
 * invalid property ("..CONFERENCE" is parsed with the name ".CONFERENCE", repaired to
 * CONFERENCE) without updating that index: select('CONFERENCE') then misses the property
 * that will be stored as a CONFERENCE, and remove() throws on it. Sabre validates the
 * object with REPAIR before emitting calendarObjectChange, so any filter selecting
 * properties by name must refresh the index first.
 */
class RepairedNameIndex {

    /**
     * Rebuilds the name index of the component and of all its sub-components, in place,
     * keeping the order of the children.
     */
    public static function refresh(Component $component): void {
        array_map(
            fn(Component $subComponent) => self::refresh($subComponent),
            $component->getComponents()
        );

        $reindex = function () {
            $this->children = array_reduce(
                $this->children(),
                function (array $index, $child) {
                    $index[strtoupper($child->name)][] = $child;
                    return $index;
                },
                []
            );
        };
        \Closure::bind($reindex, $component, Component::class)();
    }
}
