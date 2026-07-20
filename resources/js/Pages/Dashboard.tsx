import { useEffect } from 'react';

export default function Dashboard() {
    useEffect(() => {
        window.location.href = '/admin/dashboard';
    }, []);

    return null;
}
