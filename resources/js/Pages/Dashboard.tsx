import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function Dashboard() {
    useEffect(() => {
        window.location.href = '/admin/dashboard';
    }, []);

    return null;
}
