import type { FormEvent } from 'react';
import { useMemo, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Checkbox,
    DatePicker,
    EmptyCell,
    EnumBadge,
    Input,
    MapPicker,
    PhoneInput,
    SearchSelect,
    Switch,
    Tab,
    TabList,
    TabPanel,
    Tabs,
    Textarea,
    type GeocodeResult,
} from '@/Components/ui';
import {
    IndonesiaRegionFields,
    CustomerAcquirerField,
    CustomerDataFields,
    MoneyInput,
    PageHeader,
    type CodeSequence,
    type IndonesiaRegionOptions,
    type IndonesiaRegionValue,
} from '@/Components/composite';
import type { AcquirerOption } from '@/types/models';
import type { QueueUserOption } from '@/types';
import { packageGroupOptions, type PackageGroup } from '@/lib/packageGroups';
import { LOCATION_TYPE, WORK_ORDER_STATUS, labelOf } from '@/lib/statuses';
import { useMapDefault } from '@/hooks/useMapDefault';
import { DropPortSelect } from '@/Components/composite/odn/DropPortSelect';
import type { DropPortOption } from '@/types/ipam-odn';
import { SuggestionChip } from '@/Components/composite/SuggestionChip';
import { haversineKm, lowestFreeDropPort, nearestLocation, toGeoPoint } from '@/lib/suggest';
import { candidateOptions } from '@/Pages/Admin/Dispatch/components/AssignDialog';

interface AddressInput {
    label: string;
    address: string;
    city: string;
    postal_code: string;
    province_code: string;
    city_code: string;
    district_code: string;
    village_code: string;
    lat: string;
    lng: string;
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
    id: string;
    code: string;
    name: string;
    price_mrc: string | number;
    price_otc: string | number;
    contract_min_months: number;
}

interface LocationOption {
    id: string;
    code: string;
    name: string;
    type: string;
    lat: number | string | null;
    lng: number | string | null;
}

/** What the geocoder gives for an address, with the street line `OpenStreetMapGeocoder` adds to the shared type. */
type GeocodedAddress = GeocodeResult & { street?: string };

/** A free drop port as the server sends it: the picker's option and the ODP it is on. */
interface DropPortChoice extends DropPortOption {
    odp_name: string;
    free_ports: number;
    total_ports: number;
    lat: number | null;
    lng: number | null;
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
    /** Consent to WhatsApp notifications, recorded by staff with the customer. */
    whatsapp_opt_in: boolean;
    /** Local select state; sent as `acquired_by_user_id` only with the assign capability. */
    acquirer_id: string;
    /** Local select state; sent as `assign_technician_id` only with the assign capability. */
    assign_technician_id: string;
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
        /** The free ODP port chosen for the drop; sent as `odn_port_id` only when one is chosen. */
        odn_port_id: string;
        /** The visit day of the installation SPK; sent as `scheduled_date` only when one is chosen. */
        scheduled_date: string;
    };
}

interface CreateProps {
    codeSequence: CodeSequence;
    packages: PackageOption[];
    /** The same packages grouped by category, ordered for `?customer_type`. */
    packageGroups: PackageGroup[];
    locations: LocationOption[];
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
    geocodeIndex?: number;
    can: { assignAcquirer: boolean; assignDrop: boolean; assignTechnician: boolean };
    acquirerOptions: AcquirerOption[];
    acquirerDefaultId: string | null;
    /** Free ports under the serving POP, found by typing an ODP code (`dropPort_search`); odn.assign only. */
    dropPortOptions?: DropPortChoice[];
    /** The technicians "Tugaskan sekarang" offers, with the work each holds; empty without spk.assign. */
    technicians?: QueueUserOption[];
}

const emptyAddress = (): AddressInput => ({
    label: '',
    address: '',
    city: '',
    postal_code: '',
    province_code: '',
    city_code: '',
    district_code: '',
    village_code: '',
    lat: '',
    lng: '',
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

/** "850 m" under a kilometre, else "1,4 km": how far an ODP stands from the installation address. */
function formatDistance(km: number): string {
    const meters = Math.round(km * 1000);

    return meters < 1000
        ? `${meters} m`
        : `${km.toLocaleString('id-ID', { maximumFractionDigits: 1 })} km`;
}

export default function Create({
    codeSequence,
    packages,
    packageGroups,
    locations,
    regions,
    can,
    acquirerOptions,
    acquirerDefaultId,
    dropPortOptions,
    technicians = [],
    geocodeResults = [],
    geocodeIndex,
}: CreateProps) {
    const mapDefault = useMapDefault();
    const [tab, setTab] = useState('customer');
    const [geocodeSearchingIndex, setGeocodeSearchingIndex] = useState<number | null>(null);
    // "Sama dengan data pelanggan": the primary contact follows the customer's own data until it is edited.
    const [contactSameAsCustomer, setContactSameAsCustomer] = useState(true);
    // The street line the geocoder filled into each address, by index: a later pick replaces it, a typed address stays.
    const geocodedStreets = useRef<Record<number, string>>({});
    const { data, setData, post, transform, processing, errors } = useForm<CustomerForm>({
        code: '',
        name: '',
        type: 'Individual',
        email: '',
        phone: '',
        tax_id: '',
        contact_person: '',
        notes: '',
        is_active: true,
        whatsapp_opt_in: false,
        acquirer_id: acquirerDefaultId === null ? '' : String(acquirerDefaultId),
        assign_technician_id: '',
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
            odn_port_id: '',
            scheduled_date: '',
        },
    });
    // The primary contact is the customer's data while "Sama dengan data pelanggan" is on: one typing for a person who
    // registers a customer. The contact person's name wins when the customer data names one.
    const contacts = data.contacts.map((contact) =>
        contactSameAsCustomer && contact.is_primary
            ? {
                  ...contact,
                  name: data.contact_person.trim() || data.name,
                  phone: data.phone,
                  email: data.email,
              }
            : contact,
    );
    const formErrors = errors as Record<string, string | undefined>;
    const customerErrors = formErrors as Partial<
        Record<
            | 'code'
            | 'name'
            | 'type'
            | 'email'
            | 'phone'
            | 'tax_id'
            | 'contact_person'
            | 'notes'
            | 'is_active',
            string
        >
    >;

    // The drop-port search reloads the page without the serving POP unless the URL keeps it.
    const popQuery = data.subscription.serving_pop_id
        ? { serving_pop_id: data.subscription.serving_pop_id }
        : {};

    const reloadRegions = (addresses: AddressInput[]) => {
        router.get(
            route('admin.customers.create'),
            {
                ...popQuery,
                region_provinces: addresses.map((address) => address.province_code).filter(Boolean),
                region_cities: addresses.map((address) => address.city_code).filter(Boolean),
                region_districts: addresses.map((address) => address.district_code).filter(Boolean),
            },
            { only: ['regions'], preserveState: true, preserveScroll: true, replace: true },
        );
    };

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

    const updateAddressRegion = (index: number, region: IndonesiaRegionValue) => {
        const addresses = data.addresses.map((address, current) =>
            current === index ? { ...address, ...region } : address,
        );
        setData('addresses', addresses);
        reloadRegions(addresses);
    };

    const updateAddressCoordinates = (index: number, latitude: number, longitude: number) => {
        setData(
            'addresses',
            data.addresses.map((address, current) =>
                current === index
                    ? { ...address, lat: latitude.toFixed(7), lng: longitude.toFixed(7) }
                    : address,
            ),
        );
    };

    const searchAddress = (index: number, query: string) => {
        setGeocodeSearchingIndex(index);
        router.get(
            route('admin.customers.create'),
            { ...popQuery, geocode_query: query, geocode_index: index },
            {
                only: ['geocodeResults', 'geocodeIndex'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setGeocodeSearchingIndex(null),
            },
        );
    };

    const selectGeocodeResult = (index: number, result: GeocodedAddress) => {
        // The street line follows the pick unless the person wrote their own: what an earlier pick filled may be replaced.
        const street = result.street ?? '';
        const written = data.addresses[index].address;
        const takesStreet =
            street !== '' && (written.trim() === '' || written === geocodedStreets.current[index]);
        if (takesStreet) geocodedStreets.current[index] = street;

        const addresses = data.addresses.map((address, current) =>
            current === index
                ? {
                      ...address,
                      ...(takesStreet ? { address: street } : {}),
                      postal_code: result.postal_code,
                      lat: Number(result.lat).toFixed(7),
                      lng: Number(result.lng).toFixed(7),
                      province_code: result.province_code,
                      city_code: result.city_code,
                      district_code: result.district_code,
                      village_code: result.village_code,
                      city: result.city,
                  }
                : address,
        );
        setData('addresses', addresses);
        reloadRegions(addresses);
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
        // The streets the geocoder filled move with their addresses.
        geocodedStreets.current = Object.fromEntries(
            Object.entries(geocodedStreets.current)
                .filter(([key]) => Number(key) !== index)
                .map(([key, street]) => [
                    Number(key) > index ? Number(key) - 1 : Number(key),
                    street,
                ]),
        );
        setData('addresses', addresses);
    };

    const updateContact = <Key extends keyof ContactInput>(
        index: number,
        key: Key,
        value: ContactInput[Key],
    ) => {
        // Typing into the mirrored contact makes it its own: the copied values stay and the box clears.
        if (contacts[index].is_primary) setContactSameAsCustomer(false);
        setData(
            'contacts',
            contacts.map((contact, current) =>
                current === index ? { ...contact, [key]: value } : contact,
            ),
        );
    };

    const toggleContactSameAsCustomer = (checked: boolean) => {
        // Off keeps the copied values to edit; on lets the customer's data take over again.
        if (!checked) setData('contacts', contacts);
        setContactSameAsCustomer(checked);
    };

    const selectPrimaryContact = (index: number) => {
        // The contact that mirrored keeps its copied values and stops following the customer data.
        setContactSameAsCustomer(false);
        setData(
            'contacts',
            contacts.map((contact, current) => ({
                ...contact,
                is_primary: current === index,
            })),
        );
    };

    const removeContact = (index: number) => {
        const removed = contacts[index];
        const remaining = contacts
            .filter((_, current) => current !== index)
            .map((contact) => ({ ...contact }));
        if (removed.is_primary && remaining[0]) remaining[0].is_primary = true;
        if (removed.is_primary) setContactSameAsCustomer(false);
        setData('contacts', remaining);
    };

    // The server orders `packageGroups` for the type it was loaded with; the toggle re-orders here.
    const packageOptions = useMemo(
        () => packageGroupOptions(packageGroups, data.type),
        [packageGroups, data.type],
    );

    const popOptions = useMemo(
        () =>
            locations.map((location) => ({
                value: String(location.id),
                label: `${location.code} - ${location.name}`,
                description: labelOf(LOCATION_TYPE, location.type),
            })),
        [locations],
    );

    const selectPackage = (packageId: string) => {
        const selected = packages.find((item) => String(item.id) === packageId);
        setData('subscription', {
            ...data.subscription,
            service_package_id: packageId,
            mrc_amount: selected ? String(Number(selected.price_mrc)) : '',
            otc_installation_fee: selected ? String(Number(selected.price_otc)) : '',
            contract_months:
                selected && selected.contract_min_months > 0
                    ? String(selected.contract_min_months)
                    : '',
        });
    };

    /**
     * A port belongs to one POP: changing the POP drops the chosen port. The reload puts the POP in the
     * address bar, which the drop-port search needs because it sends only its own search text.
     */
    const selectServingPop = (popId: string) => {
        setData('subscription', { ...data.subscription, serving_pop_id: popId, odn_port_id: '' });

        if (can.assignDrop && popId !== '') {
            router.reload({
                only: ['dropPortOptions'],
                data: { serving_pop_id: popId },
                replace: true,
            });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        transform(({ acquirer_id, assign_technician_id, code, ...values }) => {
            // Without a chosen port or visit day the request carries neither key at all, exactly as before.
            const { odn_port_id, scheduled_date, ...subscription } = values.subscription;
            const payload = {
                ...values,
                // The primary contact as the form shows it: the customer's data while "Sama dengan data pelanggan" is on.
                contacts,
                // A locked code is generated by the server; sending it would be rejected.
                ...(codeSequence.manual ? { code } : {}),
                subscription: {
                    ...subscription,
                    ...(odn_port_id === '' ? {} : { odn_port_id }),
                    ...(scheduled_date === '' ? {} : { scheduled_date }),
                },
                ...(can.assignTechnician && assign_technician_id !== ''
                    ? { assign_technician_id }
                    : {}),
            };

            return can.assignAcquirer
                ? {
                      ...payload,
                      acquired_by_user_id: acquirer_id === '' ? null : acquirer_id,
                  }
                : payload;
        });
        post(route('admin.customers.store'), {
            onError: (validationErrors) => {
                const keys = Object.keys(validationErrors);
                if (keys.some((key) => key === 'addresses' || key.startsWith('addresses.'))) {
                    setTab('addresses');
                } else if (keys.some((key) => key === 'contacts' || key.startsWith('contacts.'))) {
                    setTab('contacts');
                } else if (
                    keys.some(
                        (key) => key.startsWith('subscription.') || key === 'assign_technician_id',
                    )
                ) {
                    setTab('subscription');
                } else {
                    setTab('customer');
                }
            },
        });
    };

    const installationLabel = data.addresses.find(
        (address) => address.is_installation_point,
    )?.label;

    // Nearest POP to the address where the line will be installed; no coordinates, no suggestion.
    const installationPoint = data.addresses.find((address) => address.is_installation_point);
    const installationGeo = toGeoPoint(installationPoint?.lat, installationPoint?.lng);
    const nearestPop = installationGeo
        ? nearestLocation(
              locations.filter((location) => location.type === 'pop'),
              installationGeo,
          )
        : null;
    // Free ports are listed after an ODP code is typed; the suggestion is the lowest one of that ODP.
    const freePort =
        data.subscription.serving_pop_id === '' ? null : lowestFreeDropPort(dropPortOptions);
    // The ports as the picker lists them: the ODP nearest the installation address first, each with its ODP's name, how many
    // of its ports are free and how far it is. Without coordinates, no distance and the server's order.
    const dropPortChoices =
        dropPortOptions === undefined
            ? undefined
            : dropPortOptions
                  .map((option) => {
                      const point = installationGeo ? toGeoPoint(option.lat, option.lng) : null;
                      const km =
                          installationGeo && point ? haversineKm(installationGeo, point) : null;

                      return { option, km };
                  })
                  .sort((a, b) =>
                      a.km === b.km ? 0 : a.km === null ? 1 : b.km === null ? -1 : a.km - b.km,
                  )
                  .map(({ option, km }) => ({
                      value: option.value,
                      label: option.label,
                      description: [
                          option.odp_name,
                          `${option.free_ports}/${option.total_ports} port kosong`,
                          km === null ? null : formatDistance(km),
                          option.description,
                      ]
                          .filter(Boolean)
                          .join(' · '),
                  }));

    return (
        <AdminLayout title="Buat Pelanggan">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Pelanggan"
                    subtitle="Buat pelanggan, alamat, kontak, langganan, dan SPK pemasangan dalam sekali simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.customers.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardContent className="pt-6">
                            <Tabs value={tab} onValueChange={setTab}>
                                <TabList className="overflow-x-auto" aria-label="Bagian pelanggan">
                                    <Tab value="customer">Data pelanggan</Tab>
                                    <Tab value="addresses">Alamat ({data.addresses.length})</Tab>
                                    <Tab value="contacts">Kontak ({data.contacts.length})</Tab>
                                    <Tab value="subscription">Langganan & SPK</Tab>
                                </TabList>

                                <TabPanel value="customer">
                                    <CustomerDataFields
                                        value={data}
                                        onChange={(key, value) => setData(key, value as never)}
                                        errors={customerErrors}
                                        codeSequence={codeSequence}
                                        mode="create"
                                        phoneHint="Link untuk mengatur kata sandi akan dikirim ke email pelanggan."
                                    />
                                    <div className="mt-4">
                                        <Checkbox
                                            label="Pelanggan setuju menerima notifikasi WhatsApp"
                                            description="Pesan yang dikirim: tagihan terbit, pengingat jatuh tempo, pembayaran diterima, status tiket, dan jadwal kunjungan teknisi. Pelanggan dapat berhenti kapan saja dengan membalas STOP."
                                            checked={data.whatsapp_opt_in}
                                            onCheckedChange={(checked) =>
                                                setData('whatsapp_opt_in', checked === true)
                                            }
                                            error={formErrors.whatsapp_opt_in}
                                        />
                                    </div>
                                    {can.assignAcquirer ? (
                                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                                            <CustomerAcquirerField
                                                options={acquirerOptions}
                                                value={data.acquirer_id}
                                                onChange={(value) => setData('acquirer_id', value)}
                                                error={formErrors.acquired_by_user_id}
                                                hint="Terisi dengan nama Anda bila Anda karyawan yang memenuhi syarat."
                                                disabled={processing}
                                            />
                                        </div>
                                    ) : null}
                                </TabPanel>

                                <TabPanel value="addresses">
                                    <div className="space-y-4">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="font-medium">Alamat pelanggan</p>
                                                <p className="text-sm text-muted-foreground">
                                                    Pilih tepat satu alamat utama dan satu alamat
                                                    pemasangan.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setData('addresses', [
                                                        ...data.addresses,
                                                        emptyAddress(),
                                                    ])
                                                }
                                            >
                                                Tambah alamat
                                            </Button>
                                        </div>
                                        {formErrors.addresses ? (
                                            <p className="text-sm text-destructive-text">
                                                {formErrors.addresses}
                                            </p>
                                        ) : null}
                                        {data.addresses.map((address, index) => (
                                            <Card key={index}>
                                                <CardHeader className="space-y-0 flex-row items-center justify-between">
                                                    <CardTitle>Alamat {index + 1}</CardTitle>
                                                    <Button
                                                        type="button"
                                                        variant="danger"
                                                        size="sm"
                                                        disabled={data.addresses.length === 1}
                                                        onClick={() => removeAddress(index)}
                                                    >
                                                        Hapus
                                                    </Button>
                                                </CardHeader>
                                                <CardContent className="grid gap-4 md:grid-cols-2">
                                                    <Input
                                                        id={`address-${index}-label`}
                                                        label="Label"
                                                        value={address.label}
                                                        placeholder="Rumah / Kantor / Pemasangan"
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'label',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={
                                                            formErrors[`addresses.${index}.label`]
                                                        }
                                                        required
                                                    />
                                                    <MapPicker
                                                        latitude={address.lat}
                                                        defaultCenter={mapDefault.center}
                                                        defaultZoom={mapDefault.zoom}
                                                        longitude={address.lng}
                                                        onChange={(latitude, longitude) =>
                                                            updateAddressCoordinates(
                                                                index,
                                                                latitude,
                                                                longitude,
                                                            )
                                                        }
                                                        latitudeError={
                                                            formErrors[`addresses.${index}.lat`]
                                                        }
                                                        longitudeError={
                                                            formErrors[`addresses.${index}.lng`]
                                                        }
                                                        searchResults={
                                                            geocodeIndex === index
                                                                ? geocodeResults
                                                                : []
                                                        }
                                                        searching={geocodeSearchingIndex === index}
                                                        onSearch={(query) =>
                                                            searchAddress(index, query)
                                                        }
                                                        onSelectSearchResult={(result) =>
                                                            selectGeocodeResult(index, result)
                                                        }
                                                    />
                                                    <IndonesiaRegionFields
                                                        idPrefix={`customer-address-${index}`}
                                                        value={address}
                                                        options={regions}
                                                        onChange={(region) =>
                                                            updateAddressRegion(index, region)
                                                        }
                                                        errors={{
                                                            province_code:
                                                                formErrors[
                                                                    `addresses.${index}.province_code`
                                                                ],
                                                            city_code:
                                                                formErrors[
                                                                    `addresses.${index}.city_code`
                                                                ],
                                                            district_code:
                                                                formErrors[
                                                                    `addresses.${index}.district_code`
                                                                ],
                                                            village_code:
                                                                formErrors[
                                                                    `addresses.${index}.village_code`
                                                                ],
                                                        }}
                                                    />
                                                    <Textarea
                                                        id={`address-${index}-address`}
                                                        className="md:col-span-2"
                                                        label="Alamat"
                                                        value={address.address}
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'address',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={
                                                            formErrors[`addresses.${index}.address`]
                                                        }
                                                        required
                                                        rows={3}
                                                    />
                                                    <Input
                                                        id={`address-${index}-postal-code`}
                                                        label="Kode pos"
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
                                                            label="Alamat utama"
                                                            checked={address.is_primary}
                                                            onCheckedChange={(checked) => {
                                                                if (checked) {
                                                                    selectAddress(
                                                                        index,
                                                                        'is_primary',
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                        <Switch
                                                            label="Alamat pemasangan"
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
                                                        id={`address-${index}-notes`}
                                                        className="md:col-span-2"
                                                        label="Catatan"
                                                        value={address.notes}
                                                        onChange={(event) =>
                                                            updateAddress(
                                                                index,
                                                                'notes',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={
                                                            formErrors[`addresses.${index}.notes`]
                                                        }
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
                                                <p className="font-medium">Kontak pelanggan</p>
                                                <p className="text-sm text-muted-foreground">
                                                    Pilih tepat satu kontak utama.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setData('contacts', [
                                                        ...data.contacts,
                                                        emptyContact(),
                                                    ])
                                                }
                                            >
                                                Tambah kontak
                                            </Button>
                                        </div>
                                        {formErrors.contacts ? (
                                            <p className="text-sm text-destructive-text">
                                                {formErrors.contacts}
                                            </p>
                                        ) : null}
                                        {contacts.map((contact, index) => (
                                            <Card key={index}>
                                                <CardHeader className="space-y-0 flex-row items-center justify-between">
                                                    <CardTitle>Kontak {index + 1}</CardTitle>
                                                    <Button
                                                        type="button"
                                                        variant="danger"
                                                        size="sm"
                                                        disabled={data.contacts.length === 1}
                                                        onClick={() => removeContact(index)}
                                                    >
                                                        Hapus
                                                    </Button>
                                                </CardHeader>
                                                <CardContent className="grid gap-4 md:grid-cols-2">
                                                    {contact.is_primary && (
                                                        <div className="space-y-1 md:col-span-2">
                                                            <Switch
                                                                label="Sama dengan data pelanggan"
                                                                checked={contactSameAsCustomer}
                                                                onCheckedChange={
                                                                    toggleContactSameAsCustomer
                                                                }
                                                            />
                                                            <p className="text-xs text-muted-foreground">
                                                                Memakai narahubung bila diisi,
                                                                telepon, dan email dari tab Data
                                                                pelanggan. Mengubah isian di bawah
                                                                melepasnya.
                                                            </p>
                                                        </div>
                                                    )}
                                                    <Input
                                                        id={`contact-${index}-name`}
                                                        label="Nama"
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
                                                        id={`contact-${index}-position`}
                                                        label="Jabatan"
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
                                                        id={`contact-${index}-phone`}
                                                        label="Telepon"
                                                        value={contact.phone}
                                                        onChange={(value) =>
                                                            updateContact(index, 'phone', value)
                                                        }
                                                        error={
                                                            formErrors[`contacts.${index}.phone`]
                                                        }
                                                        required
                                                    />
                                                    <Input
                                                        id={`contact-${index}-email`}
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
                                                        error={
                                                            formErrors[`contacts.${index}.email`]
                                                        }
                                                        required
                                                    />
                                                    <Switch
                                                        label="Kontak utama"
                                                        checked={contact.is_primary}
                                                        onCheckedChange={(checked) => {
                                                            if (checked)
                                                                selectPrimaryContact(index);
                                                        }}
                                                    />
                                                    <Textarea
                                                        id={`contact-${index}-notes`}
                                                        className="md:col-span-2"
                                                        label="Catatan"
                                                        value={contact.notes}
                                                        onChange={(event) =>
                                                            updateContact(
                                                                index,
                                                                'notes',
                                                                event.target.value,
                                                            )
                                                        }
                                                        error={
                                                            formErrors[`contacts.${index}.notes`]
                                                        }
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
                                            <p className="font-medium">
                                                Langganan dan SPK pemasangan
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                Langganan berstatus Menunggu dan SPK pemasangan
                                                dibuat otomatis saat pelanggan disimpan.
                                            </p>
                                        </div>
                                        <SearchSelect
                                            id="subscription-package"
                                            label="Paket layanan"
                                            value={data.subscription.service_package_id}
                                            onChange={selectPackage}
                                            options={packageOptions}
                                            placeholder="Pilih paket"
                                            error={formErrors['subscription.service_package_id']}
                                            required
                                        />
                                        <div className="space-y-2">
                                            <SearchSelect
                                                id="subscription-serving-pop"
                                                label="POP pelayan"
                                                value={data.subscription.serving_pop_id}
                                                onChange={selectServingPop}
                                                options={popOptions}
                                                placeholder="Pilih POP atau lokasi"
                                                error={formErrors['subscription.serving_pop_id']}
                                                required
                                            />
                                            {nearestPop &&
                                                data.subscription.serving_pop_id === '' && (
                                                    <SuggestionChip
                                                        label="Gunakan POP terdekat"
                                                        value={`${nearestPop.location.name} (${nearestPop.km.toLocaleString('id-ID', { maximumFractionDigits: 1, minimumFractionDigits: 1 })} km)`}
                                                        onUse={() =>
                                                            selectServingPop(
                                                                String(nearestPop.location.id),
                                                            )
                                                        }
                                                    />
                                                )}
                                        </div>
                                        {can.assignDrop && (
                                            <div className="space-y-2">
                                                <DropPortSelect
                                                    id="subscription-drop-port"
                                                    value={data.subscription.odn_port_id}
                                                    onChange={(value) =>
                                                        setData('subscription', {
                                                            ...data.subscription,
                                                            odn_port_id: value,
                                                        })
                                                    }
                                                    options={dropPortChoices}
                                                    unavailable={
                                                        data.subscription.serving_pop_id === ''
                                                            ? 'Pilih POP layanan untuk melihat port kosongnya.'
                                                            : undefined
                                                    }
                                                    error={formErrors['subscription.odn_port_id']}
                                                />
                                                {freePort &&
                                                    data.subscription.odn_port_id === '' && (
                                                        <SuggestionChip
                                                            label="Gunakan port kosong terendah"
                                                            value={freePort.label}
                                                            onUse={() =>
                                                                setData('subscription', {
                                                                    ...data.subscription,
                                                                    odn_port_id: freePort.value,
                                                                })
                                                            }
                                                        />
                                                    )}
                                            </div>
                                        )}
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <Input
                                                label="Tanggal tagihan (1-28)"
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
                                                label="Masa kontrak (bulan)"
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
                                                placeholder="Otomatis dari paket"
                                            />
                                            <MoneyInput
                                                id="subscription-mrc-amount"
                                                label="Biaya bulanan"
                                                value={
                                                    data.subscription.mrc_amount === ''
                                                        ? null
                                                        : Number(data.subscription.mrc_amount)
                                                }
                                                onChange={(value) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        mrc_amount:
                                                            value === null ? '' : String(value),
                                                    })
                                                }
                                                error={formErrors['subscription.mrc_amount']}
                                                placeholder="Otomatis dari paket"
                                            />
                                            <MoneyInput
                                                id="subscription-otc-installation-fee"
                                                label="Biaya pemasangan"
                                                value={
                                                    data.subscription.otc_installation_fee === ''
                                                        ? null
                                                        : Number(
                                                              data.subscription
                                                                  .otc_installation_fee,
                                                          )
                                                }
                                                onChange={(value) =>
                                                    setData('subscription', {
                                                        ...data.subscription,
                                                        otc_installation_fee:
                                                            value === null ? '' : String(value),
                                                    })
                                                }
                                                error={
                                                    formErrors['subscription.otc_installation_fee']
                                                }
                                                placeholder="Otomatis dari paket"
                                            />
                                            <Textarea
                                                className="md:col-span-2"
                                                label="Catatan langganan"
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
                                            <CardContent className="pt-6">
                                                <dl className="grid grid-cols-[max-content_1fr] items-center gap-x-6 gap-y-2 text-sm">
                                                    <dt className="text-muted-foreground">
                                                        Alamat pemasangan
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {installationLabel || <EmptyCell />}
                                                    </dd>
                                                    <dt className="text-muted-foreground">
                                                        Status SPK setelah dibuat
                                                    </dt>
                                                    <dd>
                                                        <EnumBadge
                                                            map={WORK_ORDER_STATUS}
                                                            value={
                                                                can.assignTechnician &&
                                                                data.assign_technician_id !== ''
                                                                    ? 'assigned'
                                                                    : 'generated'
                                                            }
                                                        />
                                                    </dd>
                                                </dl>
                                                <div className="space-y-4 mt-4 border-t pt-4">
                                                    <div>
                                                        <p className="font-medium">
                                                            {can.assignTechnician
                                                                ? 'Tugaskan sekarang'
                                                                : 'Jadwal kunjungan'}
                                                        </p>
                                                        <p className="text-sm text-muted-foreground">
                                                            {can.assignTechnician
                                                                ? 'Opsional. Isi bila pemasangan sudah disepakati; kosongkan untuk menugaskan nanti dari antrean Penugasan.'
                                                                : 'Opsional. Kosongkan bila belum disepakati.'}
                                                        </p>
                                                    </div>
                                                    <div className="grid gap-4 md:grid-cols-2">
                                                        {can.assignTechnician && (
                                                            <SearchSelect
                                                                id="subscription-technician"
                                                                label="Teknisi"
                                                                value={data.assign_technician_id}
                                                                onChange={(value) =>
                                                                    setData(
                                                                        'assign_technician_id',
                                                                        value,
                                                                    )
                                                                }
                                                                options={candidateOptions(
                                                                    technicians,
                                                                )}
                                                                placeholder="Cari teknisi"
                                                                emptyText="Tidak ada teknisi aktif yang cocok."
                                                                error={
                                                                    formErrors.assign_technician_id
                                                                }
                                                            />
                                                        )}
                                                        <DatePicker
                                                            id="subscription-scheduled-date"
                                                            label="Tanggal kunjungan"
                                                            value={data.subscription.scheduled_date}
                                                            onChange={(value) =>
                                                                setData('subscription', {
                                                                    ...data.subscription,
                                                                    scheduled_date: value,
                                                                })
                                                            }
                                                            error={
                                                                formErrors[
                                                                    'subscription.scheduled_date'
                                                                ]
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </TabPanel>
                            </Tabs>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.customers.index')}>Batal</Link>
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Buat pelanggan dan SPK
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
