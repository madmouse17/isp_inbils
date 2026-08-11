import * as React from 'react';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

type Theme = NonNullable<ToasterProps['theme']>;

function readDomTheme(): Theme {
    if (typeof document === 'undefined') return 'system';
    const root = document.documentElement;
    if (root.classList.contains('dark')) return 'dark';
    if (root.classList.contains('light')) return 'light';
    return 'system';
}

function useDomTheme(theme?: Theme): Theme {
    const [resolved, setResolved] = React.useState<Theme>(theme ?? 'system');

    React.useEffect(() => {
        if (theme && theme !== 'system') {
            const id = requestAnimationFrame(() => setResolved(theme));
            return () => cancelAnimationFrame(id);
        }

        let active = true;
        const sync = () => {
            if (!active) return;
            setResolved(readDomTheme());
        };
        const id = requestAnimationFrame(sync);
        const root = document.documentElement;
        const obs = new MutationObserver(sync);
        obs.observe(root, { attributes: true, attributeFilter: ['class'] });
        return () => {
            active = false;
            cancelAnimationFrame(id);
            obs.disconnect();
        };
    }, [theme]);

    if (theme && theme !== 'system') return theme;
    return resolved;
}

function Toaster({ theme, ...props }: ToasterProps) {
    const resolved = useDomTheme(theme);
    return <Sonner theme={resolved} className="toaster group" {...props} />;
}

export { Toaster };
export default Toaster;
