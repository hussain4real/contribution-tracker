import { onBeforeUnmount, onMounted, type Ref } from 'vue';

type AnimationContext = {
    revert: () => void;
};

type Gsap = typeof import('gsap')['gsap'];

type SectionTargets = {
    section: Element;
    revealTargets: Element[];
    cardTargets: Element[];
    stepTargets: Element[];
    rowTargets: Element[];
    highlightTargets: Element[];
};

function collectTargets(section: Element): SectionTargets {
    return {
        section,
        revealTargets: Array.from(
            section.querySelectorAll('[data-gsap-reveal]'),
        ),
        cardTargets: Array.from(section.querySelectorAll('[data-gsap-card]')),
        stepTargets: Array.from(section.querySelectorAll('[data-gsap-step]')),
        rowTargets: Array.from(section.querySelectorAll('[data-gsap-row]')),
        highlightTargets: Array.from(
            section.querySelectorAll('[data-gsap-highlight]'),
        ),
    };
}

function uniqueElements(groups: Element[][]): Element[] {
    return [...new Set(groups.flat())];
}

export function useGsapPublicPageAnimations(
    root: Ref<HTMLElement | null>,
): {
    animateDisclosureEnter: (element: Element, done: () => void) => void;
    animateDisclosureLeave: (element: Element, done: () => void) => void;
} {
    let motionPreference: MediaQueryList | null = null;
    let animationContext: AnimationContext | null = null;
    let observer: IntersectionObserver | null = null;
    let gsapApi: Gsap | null = null;
    const cleanupCallbacks: Array<() => void> = [];
    let animatedSections = new WeakSet<Element>();

    function prefersReducedMotion(): boolean {
        return motionPreference?.matches ?? false;
    }

    function cleanup(): void {
        cleanupCallbacks.splice(0).forEach((callback) => callback());
        observer?.disconnect();
        observer = null;
        animationContext?.revert();
        animationContext = null;
        gsapApi = null;
        animatedSections = new WeakSet<Element>();
    }

    function markStaticSections(): void {
        root.value
            ?.querySelectorAll('[data-gsap-section]')
            .forEach((section) => {
                section.setAttribute('data-gsap-state', 'static');
            });
    }

    function animateSection(section: Element): void {
        if (!gsapApi || animatedSections.has(section)) {
            return;
        }

        animatedSections.add(section);
        section.setAttribute('data-gsap-state', 'animated');

        const targets = collectTargets(section);
        const timeline = gsapApi.timeline({
            defaults: { duration: 0.55, ease: 'power3.out' },
        });

        if (targets.revealTargets.length) {
            timeline.to(targets.revealTargets, {
                autoAlpha: 1,
                y: 0,
                stagger: 0.08,
            });
        }

        if (targets.cardTargets.length) {
            timeline.to(
                targets.cardTargets,
                {
                    autoAlpha: 1,
                    scale: 1,
                    y: 0,
                    stagger: 0.07,
                },
                targets.revealTargets.length ? '-=0.28' : 0,
            );
        }

        if (targets.stepTargets.length) {
            timeline.to(
                targets.stepTargets,
                {
                    autoAlpha: 1,
                    scale: 1,
                    y: 0,
                    stagger: 0.12,
                },
                targets.revealTargets.length ? '-=0.28' : 0,
            );
        }

        if (targets.rowTargets.length) {
            timeline.to(
                targets.rowTargets,
                {
                    autoAlpha: 1,
                    x: 0,
                    stagger: 0.035,
                },
                targets.revealTargets.length ? '-=0.18' : 0,
            );
        }

        if (targets.highlightTargets.length) {
            timeline.to(
                targets.highlightTargets,
                {
                    scale: 1.035,
                    duration: 0.38,
                    repeat: 1,
                    yoyo: true,
                    ease: 'sine.inOut',
                },
                '-=0.15',
            );
        }
    }

    function setupHoverTargets(): void {
        if (!root.value || !gsapApi) {
            return;
        }

        root.value.querySelectorAll('[data-gsap-hover]').forEach((target) => {
            const enter = (): void => {
                if (prefersReducedMotion()) {
                    return;
                }

                gsapApi?.to(target, {
                    duration: 0.22,
                    ease: 'power2.out',
                    scale: 1.018,
                    y: -6,
                });
            };

            const leave = (): void => {
                gsapApi?.to(target, {
                    duration: 0.2,
                    ease: 'power2.out',
                    scale: 1,
                    y: 0,
                });
            };

            target.addEventListener('pointerenter', enter);
            target.addEventListener('pointerleave', leave);
            target.addEventListener('focusin', enter);
            target.addEventListener('focusout', leave);
            cleanupCallbacks.push(() => {
                target.removeEventListener('pointerenter', enter);
                target.removeEventListener('pointerleave', leave);
                target.removeEventListener('focusin', enter);
                target.removeEventListener('focusout', leave);
            });
        });
    }

    async function setupAnimations(): Promise<void> {
        if (!root.value) {
            return;
        }

        cleanup();

        motionPreference ??= window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        );

        if (prefersReducedMotion()) {
            markStaticSections();

            return;
        }

        const { gsap } = await import('gsap');

        if (!root.value || prefersReducedMotion()) {
            markStaticSections();

            return;
        }

        gsapApi = gsap;
        const animationRoot = root.value;

        animationContext = gsap.context(() => {
            const sections = Array.from(
                animationRoot.querySelectorAll('[data-gsap-section]'),
            );

            sections.forEach((section) => {
                section.setAttribute('data-gsap-state', 'pending');

                const targets = collectTargets(section);
                const initialTargets = uniqueElements([
                    targets.revealTargets,
                    targets.cardTargets,
                    targets.stepTargets,
                    targets.rowTargets,
                ]);

                if (initialTargets.length) {
                    gsap.set(initialTargets, { autoAlpha: 0 });
                }

                if (targets.revealTargets.length) {
                    gsap.set(targets.revealTargets, { y: 18 });
                }

                if (targets.cardTargets.length) {
                    gsap.set(targets.cardTargets, { scale: 0.98, y: 22 });
                }

                if (targets.stepTargets.length) {
                    gsap.set(targets.stepTargets, { scale: 0.96, y: 24 });
                }

                if (targets.rowTargets.length) {
                    gsap.set(targets.rowTargets, { x: -12 });
                }
            });

            observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            animateSection(entry.target);
                            observer?.unobserve(entry.target);
                        }
                    });
                },
                {
                    rootMargin: '0px 0px -12% 0px',
                    threshold: 0.18,
                },
            );

            sections.forEach((section) => observer?.observe(section));
            setupHoverTargets();
        }, animationRoot);
    }

    function handleMotionPreferenceChange(): void {
        void setupAnimations();
    }

    async function animateDisclosure(
        element: Element,
        done: () => void,
        isEntering: boolean,
    ): Promise<void> {
        if (prefersReducedMotion()) {
            done();

            return;
        }

        const { gsap } = await import('gsap');
        const target = element as HTMLElement;

        if (isEntering) {
            gsap.fromTo(
                target,
                { autoAlpha: 0, height: 0 },
                {
                    autoAlpha: 1,
                    clearProps: 'height,opacity,visibility',
                    duration: 0.24,
                    ease: 'power2.out',
                    height: 'auto',
                    onComplete: done,
                },
            );

            return;
        }

        gsap.to(target, {
            autoAlpha: 0,
            duration: 0.18,
            ease: 'power2.in',
            height: 0,
            onComplete: done,
        });
    }

    onMounted(() => {
        void setupAnimations();
        motionPreference?.addEventListener(
            'change',
            handleMotionPreferenceChange,
        );
    });

    onBeforeUnmount(() => {
        motionPreference?.removeEventListener(
            'change',
            handleMotionPreferenceChange,
        );
        cleanup();
    });

    return {
        animateDisclosureEnter: (element, done): void => {
            void animateDisclosure(element, done, true);
        },
        animateDisclosureLeave: (element, done): void => {
            void animateDisclosure(element, done, false);
        },
    };
}
