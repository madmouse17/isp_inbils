import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    NativeSelect,
    PhoneInput,
    Switch,
    Tab,
    TabList,
    TabPanel,
    Tabs,
    Textarea,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface AddressInput {
    label: string;
    address: string;
    city: string;
    postal_code: string;
    is_installation_point: boolean;
    is_primary: boolean;
    notes: string;
}

interface ContactInput {
    name: string;
    position: string;
    phone: string;
    email: string;
    is_primary: boolean;
    notes: string;
}

interface PackageOption {
    id: number;
    code: string;
    name: string;
    price_mrc: string | number;
    price_otc: string | number;
    contract_min_months: number;
}

interface LocationOption {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface CustomerForm {
    code: string;
    name: string;
    type: 'Individual' | 'Company';
    email: string;
    phone: string;
    tax_id: string;
    contact_person: string;
    notes: string;
    is_active: boolean;
    addresses: AddressInput[];
    contacts: ContactInput[];
    subscription: {
        service_package_id: string;
        serving_pop_id: string;
        billing_day: string;
        mrc_amount: string;
        otc_installation_fee: string;
        contract_months: string;
        notes: string;
    };
}

interface CreateProps {
    packages: PackageOption[];
    locations: LocationOption[];
}

const emptyAddress = (): AddressInput => ({
    label: '',
    address: '',
    city: '',
    postal_code: '',
    is_installation_point: false,
    is_primary: false,
    notes: '',
});

const emptyContact = (): ContactInput => ({
    name: '',
    position: '',
    phone: '',
    email: '',
    is_primary: false,
    notes: '',
});

export default function Create({ packages, locations }: CreateProps) {
    const [tab, setTab] = useState('customer');
    const { data, setData, post, processing, errors } = useForm<CustomerForm>({
        code: '',
        name: '',
        type: 'Individual',
        email: '',
        phone: '',
        tax_id: '',
        contact_person: '',
        notes: '',
        is_active: true,
        addresses: [
            {
                ...emptyAddress(),
                label: '',
                is_installation_point: true,
                is_primary: true,
            },
        ],
        contacts: [{ ...emptyContact(), is_primary: true }],
        subscription: {
            service_package_id: '',
            serving_pop_id: '',
            billing_day: '1',
            mrc_amount: '',
            otc_installation_fee: '',
            contract_months: '',
            notes: '',
        },
    });
    const formErrors = errors as Record<string, string | undefined>;

    const updateAddress = <Key extends keyof AddressInput>(
        index: number,
        key: Key,
        value: AddressInput[Key],
    ) => {
        setData(
            'addresses',
            data.addresses.map((address, current) =>
                current === index ? { ...address, [key]: value } : address,
            ),
        );
    };

    const selectAddress = (index: number, key: 'is_primary' | 'is_installation_point') => {
        setData(
            'addresses',
            data.addresses.map((address, current) => ({
                ...address,
                [key]: current === index,
            })),
        );
    };

    const removeAddress = (index: number) => {
        const removed = data.addresses[index];
        const addresses = data.addresses
            .filter((_, current) => current !== index)
            .map((address) => ({ ...address }));
        if (removed.is_primary && addresses[0]) addresses[0].is_primary = true;
        if (removed.is_installation_point && addresses[0]) {
            addresses[0].is_installation_point = true;
        }
        setData('addresses', addresses);
    };

    const updateContact = <Key extends keyof ContactInput>(
        index: number,
        key: Key,
        value: ContactInput[Key],
    ) => {
        setData(
            'contacts',
            data.contacts.map((contact, current) =>
                current === index ? { ...contact, [key]: value } : contact,
            ),
        );
    };

    const selectPrimaryContact = (index: number) => {
        setData(
            'contacts',
            data.contacts.map((contact, current) => ({
                ...contact,
                is_primary: current === index,
            })),
        );
    };

    const removeContact = (index: number) => {
        const removed = data.contacts[index];
        const contacts = data.contacts
            .filter((_, current) => current !== index)
            .map((contact) => ({ ...contact }));
        if (removed.is_primary && contacts[0]) contacts[0].is_primary = true;
        setData('contacts', contacts);
    };

    const selectPackage = (packageId: string) => {
        const selected = packages.find((item) => String(item.id) === packageId);
        setData('subscription', {
            ...data.subscription,
            service_package_id: packageId,
            mrc_amount: selected ? String(selected.price_mrc) : '',
            otc_installation_fee: selected ? String(selected.price_otc) : '',
            contract_months:
                selected && selected.contract_min_months > 0
                    ? String(selected.contract_min_months)
                    : '',
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.customers.store'), {
            onError: (validationErrors) => {
                const keys = Object.keys(validationErrors);
                if (keys.some((key) => key === 'addresses' || key.startsWith('addresses.'))) {
                    setTab('addresses');
                } else if (
                    keys.some((key) => key === 'contacts' || key.startsWith('contacts.'))
                ) {
                    setTab('contacts');
                } else if (keys.some((key) => key.startsWith('subscription.'))) {
                    setTab('subscription');
                } else {
                    setTab('customer');
                }
            },
        });
    };

    return (
        <AdminLayout title="Create Customer">
            <div className="space-y-6">
                <PageHeader
                    title="Create Customer"
                    subtitle="Create customer, addresses, contacts, subscription, and installation SPK in one submission."
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardContent className="pt-6">
                            <Tabs value={tab} onValueChange={setTab}>
                                <TabList className="overflow-x-auto">
                                    <Tab value="customer">Customer Data</Tab>
                                    <Tab value="addresses">Addresses ({data.addresses.length})</Tab>
                                    <Tab value="contacts">Contacts ({data.contacts.length})</Tab>
                                    <Tab value="subscription">Subscription & SPK</Tab>
                                </TabList>

                                <TabPanel value="customer">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <Input label="Code" value={data.code} onChange={(event) => setData('code', event.target.value)} error={errors.code} required placeholder="CUS-2026-00001" />
                                        <Input label="Name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                                        <NativeSelect label="Type" value={data.type} onChange={(event) => setData('type', event.target.value as CustomerForm['type'])} error={errors.type}>
                                            <option value="Individual">Individual</option>
                                            <option value="Company">Company</option>
                                        </NativeSelect>
                                        <Input label="Email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} required />
                                        <PhoneInput label="Phone" value={data.phone} onChange={(value) => setData('phone', value)} error={errors.phone} hint="Password user customer sama dengan nomor phone." required />
                                        <Input label="Tax ID (NPWP)" value={data.tax_id} onChange={(event) => setData('tax_id', event.target.value)} error={errors.tax_id} required={data.type === 'Company'} />
                                        <Input label="Contact Person" value={data.contact_person} onChange={(event) => setData('contact_person', event.target.value)} error={errors.contact_person} required={data.type === 'Company'} />
                                        <div className="flex items-end"><Switch label="Active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked)} /></div>
                                        <Textarea className="md:col-span-2" label="Notes" value={data.notes} onChange={(event) => setData('notes', event.target.value)} error={errors.notes} rows={3} />
                                    </div>
                                </TabPanel>

                                <TabPanel value="addresses">
                                    <div className="space-y-4">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="font-medium">Customer Addresses</p>
                                                <p className="text-sm text-muted-foreground">
                                                    Select exactly one primary and one installation address.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="success"
                                                size="sm"
                                                onClick={() =>
                                                    setData('addresses', [
                                                        ...data.addresses,
                                                        emptyAddress(),
                                                    ])
                                                }
                                            >
                                                Add Address
                                            </Button>
                                        </div>
                                        {formErrors.addresses ? (
                                            <p className="text-sm text-destructive">
                                                {formErrors.addresses}
                                            </p>
                                        ) : null}
                                        {data.addresses.map((address, index) => (
                                            <Card key={index}>
                                                <CardHeader className="flex-row items-center justify-between space-y-0">
                                                    <CardTitle>Address {index + 1}</CardTitle>
                                                    <Button
                                                        type="button"
                                                        variant="danger"
                                                        size="sm"
                                                        disabled={data.addresses.length === 1}
                                                        onClick={() => removeAddress(index)}
                                                    >
                                                        Remove
                                                    </Button>
                                                </CardHeader>
                                                <CardContent className="grid gap-4 md:grid-cols-2">
                                                    <Input
                                                        label="Label"
                                                        value={address.label}
                                                        placeholder="Main / Office / Instalation/Home"
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'label',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`addresses.${index}.label`]}
                                                        required
                                                    />
                                                    <Input
                                                        label="City"
                                                        value={address.city}
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'city',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`addresses.${index}.city`]}
                                                    />
                                                    <Textarea
                                                        className="md:col-span-2"
                                                        label="Address"
                                                        value={address.address}
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'address',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`addresses.${index}.address`]}
                                                        required
                                                        rows={3}
                                                    />
                                                    <Input
                                                        label="Postal Code"
                                                        value={address.postal_code}
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'postal_code',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={
                                                            formErrors[
                                                                `addresses.${index}.postal_code`
                                                            ]
                                                        }
                                                    />
                                                    <div className="flex flex-wrap items-end gap-4">
                                                        <Switch
                                                            label="Primary address"
                                                            checked={address.is_primary}
                                                            onCheckedChange={(checked) => {
                                                                if (checked) {
                                                                    selectAddress(index, 'is_primary');
                                                                }
                                                            }}
                                                        />
                                                        <Switch
                                                            label="Installation address"
                                                            checked={address.is_installation_point}
                                                            onCheckedChange={(checked) => {
                                                                if (checked) {
                                                                    selectAddress(
                                                                        index,
                                                                        'is_installation_point',
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                    </div>
                                                    <Textarea
                                                        className="md:col-span-2"
                                                        label="Notes"
                                                        value={address.notes}
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'notes',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`addresses.${index}.notes`]}
                                                        rows={2}
                                                    />
                                                </CardContent>
                                            </Card>
                                        ))}
                                    </div>
                                </TabPanel>
                                <TabPanel value="contacts">
                                    <div className="space-y-4">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="font-medium">Customer Contacts</p>
                                                <p className="text-sm text-muted-foreground">
                                                    Select exactly one primary contact.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="success"
                                                size="sm"
                                                onClick={() =>
                                                    setData('contacts', [
                                                        ...data.contacts,
                                                        emptyContact(),
                                                    ])
                                                }
                                            >
                                                Add Contact
                                            </Button>
                                        </div>
                                        {formErrors.contacts ? (
                                            <p className="text-sm text-destructive">
                                                {formErrors.contacts}
                                            </p>
                                        ) : null}
                                        {data.contacts.map((contact, index) => (
                                            <Card key={index}>
                                                <CardHeader className="flex-row items-center justify-between space-y-0">
                                                    <CardTitle>Contact {index + 1}</CardTitle>
                                                    <Button
                                                        type="button"
                                                        variant="danger"
                                                        size="sm"
                                                        disabled={data.contacts.length === 1}
                                                        onClick={() => removeContact(index)}
                                                    >
                                                        Remove
                                                    </Button>
                                                </CardHeader>
                                                <CardContent className="grid gap-4 md:grid-cols-2">
                                                    <Input
                                                        label="Name"
                                                        value={contact.name}
                                                        onChange={(event) =>
                                                            updateContact(
                                                                index,
                                                                'name',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`contacts.${index}.name`]}
                                                        required
                                                    />
                                                    <Input
                                                        label="Position"
                                                        value={contact.position}
                                                        onChange={(event) =>
                                                            updateContact(
                                                                index,
                                                                'position',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={
                                                            formErrors[`contacts.${index}.position`]
                                                        }
                                                    />
                                                    <PhoneInput
                                                        label="Phone"
                                                        value={contact.phone}
                                                        onChange={(value) =>
                                                            updateContact(index, 'phone', value)
                                                        }
                                                        error={formErrors[`contacts.${index}.phone`]}
                                                        required
                                                    />
                                                    <Input
                                                        label="Email"
                                                        type="email"
                                                        value={contact.email}
                                                        onChange={(event) =>
                                                            updateContact(
                                                                index,
                                                                'email',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`contacts.${index}.email`]}
                                                        required
                                                    />
                                                    <Switch
                                                        label="Primary contact"
                                                        checked={contact.is_primary}
                                                        onCheckedChange={(checked) => {
                                                            if (checked) selectPrimaryContact(index);
                                                        }}
                                                    />
                                                    <Textarea
                                                        className="md:col-span-2"
                                                        label="Notes"
                                                        value={contact.notes}
                                                        onChange={(event) =>
                                                            updateContact(
                                                                index,
                                                                'notes',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={formErrors[`contacts.${index}.notes`]}
                                                        rows={2}
                                                    />
                                                </CardContent>
                                            </Card>
                                        ))}
                                    </div>
                                </TabPanel>
                                <TabPanel value="subscription">
                                    <div className="space-y-4">
                                        <div>
                                            <p className="font-medium">Subscription & Installation SPK</p>
                                            <p className="text-sm text-muted-foreground">
                                                A pending subscription and generated installation SPK will be created automatically.
                                            </p>
                                        </div>
                                        <NativeSelect
                                            label="Service Package"
                                            value={data.subscription.service_package_id}
                                            onChange={(event) => selectPackage(event.target.value)}
                                            error={formErrors['subscription.service_package_id']}
                                            options={[
                                                { value: '', label: 'Select package…' },
                                                ...packages.map((item) => ({
                                                    value: String(item.id),
                                                    label: `${item.code} — ${item.name}`,
                                                })),
                                            ]}
                                            required
                                        />
                                        <NativeSelect
                                            label="Serving POP / Location"
                                            value={data.subscription.serving_pop_id}
                                            onChange={(event) =>
                                                setData('subscription', {
                                                    ...data.subscription,
                                                    serving_pop_id: event.target.value,
                                                })
                                            }
                                            error={formErrors['subscription.serving_pop_id']}
                                            options={[
                                                { value: '', label: 'Select location…' },
                                                ...locations.map((location) => ({
                                                    value: String(location.id),
                                                    label: `${location.code} — ${location.name} (${location.type})`,
                                                })),
                                            ]}
                                            required
                                        />
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <Input
                                                label="Billing Day"
                                                type="number"
                                                min={1}
                                                max={28}
                                                value={data.subscription.billing_day}
                                                onChange={(event) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        billing_day: event.target.value,
                                                    })
                                                }
                                                error={formErrors['subscription.billing_day']}
                                                required
                                            />
                                            <Input
                                                label="Contract Months"
                                                type="number"
                                                min={1}
                                                value={data.subscription.contract_months}
                                                onChange={(event) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        contract_months: event.target.value,
                                                    })
                                                }
                                                error={formErrors['subscription.contract_months']}
                                                placeholder="Auto from package"
                                            />
                                            <Input
                                                label="MRC Amount"
                                                type="number"
                                                min={0}
                                                value={data.subscription.mrc_amount}
                                                onChange={(event) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        mrc_amount: event.target.value,
                                                    })
                                                }
                                                error={formErrors['subscription.mrc_amount']}
                                                placeholder="Auto from package"
                                            />
                                            <Input
                                                label="OTC Installation Fee"
                                                type="number"
                                                min={0}
                                                value={data.subscription.otc_installation_fee}
                                                onChange={(event) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        otc_installation_fee: event.target.value,
                                                    })
                                                }
                                                error={
                                                    formErrors[
                                                        'subscription.otc_installation_fee'
                                                    ]
                                                }
                                                placeholder="Auto from package"
                                            />
                                            <Textarea
                                                className="md:col-span-2"
                                                label="Subscription Notes"
                                                value={data.subscription.notes}
                                                onChange={(event) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        notes: event.target.value,
                                                    })
                                                }
                                                error={formErrors['subscription.notes']}
                                                rows={3}
                                            />
                                        </div>
                                        <Card>
                                            <CardContent className="space-y-2 pt-6 text-sm">
                                                <p>
                                                    Installation address:{' '}
                                                    <span className="font-medium">
                                                        {data.addresses.find(
                                                            (address) =>
                                                                address.is_installation_point,
                                                        )?.label || 'Not selected'}
                                                    </span>
                                                </p>
                                                <p>
                                                    SPK status after create:{' '}
                                                    <span className="font-medium">Generated</span>
                                                </p>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </TabPanel>
                            </Tabs>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(route('admin.customers.index'))}>Cancel</Button>
                        <Button type="submit" variant="success" loading={processing}>Create Customer & SPK</Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
