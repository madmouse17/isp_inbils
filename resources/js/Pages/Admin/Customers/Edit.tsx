import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    Tab,
    TabList,
    TabPanel,
    Tabs,
    type GeocodeResult,
} from '@/Components/ui';
import {
    CustomerAcquirerField,
    CustomerAddressEditor,
    CustomerContactEditor,
    CustomerDataFields,
    PageHeader,
    type CustomerDataValue,
    type IndonesiaRegionOptions,
} from '@/Components/composite';
import type { AcquirerOption, Customer } from '@/types/models';

interface EditPageProps {
    customer: { data: Customer };
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
    geocodeIndex?: number;
    acquirerOptions: AcquirerOption[];
    can: {
        update: boolean;
        address: boolean;
        contact: boolean;
        viewAcquirer: boolean;
        assignAcquirer: boolean;
        correctAcquirer: boolean;
    };
}

interface CustomerEditForm extends CustomerDataValue {
    /** Selected acquirer id (`''` = Unassigned); sent as `acquired_by_user_id` only when changed. */
    acquirer_id: string;
    acquirer_correction_reason: string;
}

export default function Edit({
    customer,
    regions,
    geocodeResults = [],
    geocodeIndex,
    acquirerOptions,
    can,
}: EditPageProps) {
    const c = customer.data;
    const addresses = c.addresses ?? [];
    const contacts = c.contacts ?? [];
    const readOnly = !can.update;
    const [tab, setTab] = useState('customer');

    const initialAcquirerId = c.acquired_by_user_id == null ? '' : String(c.acquired_by_user_id);

    const { data, setData, put, transform, processing, errors, reset } = useForm<CustomerEditForm>({
        code: c.code ?? '',
        name: c.name ?? '',
        type: (c.type as CustomerDataValue['type']) ?? 'Individual',
        email: c.email ?? '',
        phone: c.phone ?? '',
        tax_id: c.tax_id ?? '',
        contact_person: c.contact_person ?? '',
        notes: c.notes ?? '',
        is_active: Boolean(c.is_active),
        acquirer_id: initialAcquirerId,
        acquirer_correction_reason: '',
    });
    const formErrors = errors as Partial<Record<keyof CustomerDataValue, string>>;
    const acquirerErrors = errors as Partial<
        Record<'acquired_by_user_id' | 'acquirer_correction_reason', string>
    >;
    // Unassigned -> assign needs the assign capability; set -> change/clear is a correction.
    const acquirerEditable =
        can.viewAcquirer &&
        !readOnly &&
        (initialAcquirerId === '' ? can.assignAcquirer : can.correctAcquirer);
    const acquirerChanged = acquirerEditable && data.acquirer_id !== initialAcquirerId;
    const requireReason = acquirerChanged && initialAcquirerId !== '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (processing || readOnly) return;
        transform(({ acquirer_id, acquirer_correction_reason, ...values }) => ({
            ...values,
            ...(acquirerChanged
                ? { acquired_by_user_id: acquirer_id === '' ? null : Number(acquirer_id) }
                : {}),
            ...(requireReason
                ? { acquirer_correction_reason: acquirer_correction_reason.trim() }
                : {}),
        }));
        // Scalar fields only — addresses/contacts are managed independently.
        put(route('admin.customers.update', c.id), {
            preserveScroll: true,
            onError: (validationErrors) => {
                if (Object.keys(validationErrors).length > 0) {
                    setTab('customer');
                }
            },
            onSuccess: () => setData('acquirer_correction_reason', ''),
        });
    };

    const cancel = () => {
        // Discard the draft before leaving so stale values never resurface.
        reset();
        router.get(route('admin.customers.show', c.id));
    };

    return (
        <AdminLayout title="Edit Customer">
            <div className="space-y-6">
                <PageHeader
                    title="Edit Customer"
                    subtitle={
                        readOnly
                            ? 'Read-only view. You do not have permission to update this customer.'
                            : 'Update customer data, addresses, and contacts.'
                    }
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.customers.show', customer.data.id)}>Back</Link>
                        </Button>
                    }
                />
                <Card>
                    <CardContent className="pt-6">
                        <Tabs value={tab} onValueChange={setTab}>
                            <TabList className="overflow-x-auto" aria-label="Customer sections">
                                <Tab value="customer">Customer Data</Tab>
                                <Tab value="addresses">Addresses ({addresses.length})</Tab>
                                <Tab value="contacts">Contacts ({contacts.length})</Tab>
                            </TabList>

                            <TabPanel value="customer">
                                {/* The only <form> on the page — nested editors render their own
                                    forms inside separate tab panels, so forms never nest. */}
                                <form
                                    onSubmit={submit}
                                    noValidate
                                    className="space-y-4"
                                    aria-busy={processing}
                                >
                                    <CustomerDataFields
                                        value={data}
                                        onChange={(key, value) => setData(key, value as never)}
                                        errors={formErrors}
                                        readOnly={readOnly}
                                    />
                                    {can.viewAcquirer ? (
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <CustomerAcquirerField
                                                options={acquirerOptions}
                                                value={data.acquirer_id}
                                                onChange={(value) => setData('acquirer_id', value)}
                                                error={acquirerErrors.acquired_by_user_id}
                                                disabled={processing}
                                                current={c.acquirer ?? null}
                                                readOnly={!acquirerEditable}
                                                requireReason={requireReason}
                                                reason={data.acquirer_correction_reason}
                                                onReasonChange={(value) =>
                                                    setData('acquirer_correction_reason', value)
                                                }
                                                reasonError={
                                                    acquirerErrors.acquirer_correction_reason
                                                }
                                            />
                                        </div>
                                    ) : null}
                                    <div className="flex justify-end gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={cancel}
                                            disabled={processing}
                                        >
                                            {readOnly ? 'Back' : 'Cancel'}
                                        </Button>
                                        {!readOnly ? (
                                            <Button
                                                type="submit"
                                                loading={processing}
                                                disabled={processing}
                                            >
                                                Save Customer
                                            </Button>
                                        ) : null}
                                    </div>
                                </form>
                            </TabPanel>

                            <TabPanel value="addresses">
                                <CustomerAddressEditor
                                    customerId={Number(c.id)}
                                    addresses={addresses}
                                    regions={regions}
                                    geocodeResults={geocodeResults}
                                    geocodeIndex={geocodeIndex}
                                    canManage={can.address && !readOnly}
                                />
                            </TabPanel>

                            <TabPanel value="contacts">
                                <CustomerContactEditor
                                    customerId={Number(c.id)}
                                    contacts={contacts}
                                    canManage={can.contact && !readOnly}
                                />
                            </TabPanel>
                        </Tabs>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
