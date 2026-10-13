import { useMemo } from 'react';
import { useCompany } from './useCompany';
import { resolveMapDefault, type MapDefault } from './mapDefault.helpers';

/** Where the company's maps open when a record has no coordinates of its own. */
export function useMapDefault(): MapDefault {
    const map = useCompany()?.map;

    return useMemo(() => resolveMapDefault(map), [map]);
}
