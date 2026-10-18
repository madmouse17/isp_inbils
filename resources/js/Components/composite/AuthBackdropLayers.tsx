import { useMotionValueEvent, useMotionValue, useReducedMotion, useSpring } from 'motion/react';
import { useEffect, useRef, type CSSProperties } from 'react';

const SPRING = { stiffness: 60, damping: 20, mass: 0.6 };

/** Quarter arcs and nodes radiating from one corner, the signal motif of the brand mark. */
function ArcField({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 640 640"
            fill="none"
            aria-hidden
            className={className}
            stroke="currentColor"
            strokeWidth="1.5"
        >
            <path d="M0 440A200 200 0 0 1 200 640" />
            <path d="M0 300A340 340 0 0 1 340 640" />
            <path d="M0 160A480 480 0 0 1 480 640" />
            <g fill="currentColor" stroke="none">
                <circle cx="0" cy="640" r="7" />
                <circle cx="141" cy="499" r="5" />
                <circle cx="240" cy="400" r="5" />
                <circle cx="339" cy="301" r="5" />
                <circle cx="70" cy="570" r="3" />
                <circle cx="200" cy="640" r="4" />
                <circle cx="340" cy="640" r="4" />
                <circle cx="480" cy="640" r="4" />
                <circle cx="0" cy="440" r="4" />
                <circle cx="0" cy="300" r="4" />
                <circle cx="0" cy="160" r="4" />
            </g>
            <path
                d="M141 499L240 400L339 301M141 499L200 640M240 400L340 640M339 301L480 640"
                strokeOpacity="0.6"
            />
        </svg>
    );
}

/**
 * Light ambient backdrop for the sign-in pages: two drifting colour fields plus a faint node pattern.
 * The pointer moves the two layers at different depths. Touch screens and reduced-motion users get the static layers.
 */
/**
 * Light ambient backdrop for the sign-in pages: two drifting colour fields plus a faint node pattern.
 * The pointer moves the two layers at different depths. Touch screens and reduced-motion users get the static layers.
 */
export default function AuthBackdropLayers() {
    const reduced = useReducedMotion();
    const px = useMotionValue(0);
    const py = useMotionValue(0);
    const sx = useSpring(px, SPRING);
    const sy = useSpring(py, SPRING);
    const blobs = useRef<HTMLDivElement>(null);
    const arcs = useRef<HTMLDivElement>(null);

    // Writes transform straight to the DOM so a pointer move never re-renders React.
    const place = () => {
        if (blobs.current) {
            blobs.current.style.transform = `translate3d(${sx.get() * -28}px, ${sy.get() * -28}px, 0)`;
        }
        if (arcs.current) {
            arcs.current.style.transform = `translate3d(${sx.get() * 12}px, ${sy.get() * 12}px, 0)`;
        }
    };
    useMotionValueEvent(sx, 'change', place);
    useMotionValueEvent(sy, 'change', place);

    useEffect(() => {
        if (reduced || !window.matchMedia('(hover: hover)').matches) {
            return;
        }

        const onMove = (e: PointerEvent) => {
            px.set((e.clientX / window.innerWidth - 0.5) * 2);
            py.set((e.clientY / window.innerHeight - 0.5) * 2);
        };

        window.addEventListener('pointermove', onMove, { passive: true });

        return () => window.removeEventListener('pointermove', onMove);
    }, [reduced, px, py]);

    return (
        <div
            aria-hidden
            className="auth-fade pointer-events-none fixed inset-0 -z-10 overflow-hidden"
        >
            <div ref={blobs} className="absolute inset-0">
                <div
                    className="auth-blob auth-drift absolute -top-40 -left-40 size-[40rem] rounded-full"
                    style={
                        {
                            '--blob': 'var(--color-brand-500)',
                            '--dx': '4rem',
                            '--dy': '3rem',
                        } as CSSProperties
                    }
                />
                <div
                    className="auth-blob auth-drift absolute -right-48 -bottom-48 size-[46rem] rounded-full [animation-delay:-12s]"
                    style={
                        {
                            '--blob': 'var(--color-brand-300)',
                            '--dx': '-3rem',
                            '--dy': '-4rem',
                        } as CSSProperties
                    }
                />
            </div>
            <div ref={arcs} className="absolute inset-0 text-brand-600/20 dark:text-brand-400/15">
                <ArcField className="absolute bottom-0 left-0 w-[min(40rem,90vw)]" />
                <ArcField className="absolute top-0 right-0 hidden w-[28rem] rotate-180 lg:block" />
            </div>
        </div>
    );
}
