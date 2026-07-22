import { router, usePage } from '@inertiajs/vue3';

/**
 * Company-owned records (invoices, projects, attendance, …) belong to exactly
 * ONE company. A Super Admin browsing "all companies" has none selected, so a
 * create is impossible until they pick one.
 *
 * Rather than HIDE the create button — which reads as "the feature is broken"
 * (a real support report) — every company-owned page shows the button and
 * calls this on click. When a company is active it returns true and the form
 * opens as normal; when none is (only ever a Super Admin browsing all), it
 * sends them to the company picker instead of opening a form that would bounce
 * on submit. Company Admins and Users always have a company, so for them this
 * is a no-op that always returns true.
 *
 * @returns {boolean} true when it is safe to proceed; false when it redirected
 */
export function ensureCompanySelected() {
    const page = usePage();

    if (page.props.company) {
        return true;
    }

    router.visit('/welcome');

    return false;
}
