import { useState } from 'react';
import { EllipsisVertical, Inbox, Search } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { LoadingState, PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import {
    Alert,
    Avatar,
    Badge,
    Breadcrumb,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
    Checkbox,
    DatePicker,
    DateTimePicker,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    EmptyState,
    EnumBadge,
    IconButton,
    Input,
    Label,
    Modal,
    MonthPicker,
    NativeSelect,
    Pagination,
    Progress,
    Radio,
    RadioGroup,
    SearchSelect,
    Skeleton,
    Spinner,
    StatCard,
    Switch,
    Tab,
    TabList,
    TabPanel,
    Table,
    Tabs,
    TBody,
    TD,
    TH,
    THead,
    TimeInput,
    Textarea,
    Tooltip,
    TR,
    useToast,
} from '@/Components/ui';
import {
    ACTIVE_STATE,
    INVOICE_STATUS,
    PRIORITY,
    SUBSCRIPTION_STATUS,
    TICKET_STATUS,
    optionsOf,
} from '@/lib/statuses';
import type { StatusMap } from '@/lib/statuses';

const sections = [
    { id: 'buttons', label: 'Tombol' },
    { id: 'inputs', label: 'Input & Form' },
    { id: 'dates', label: 'Tanggal & Jam' },
    { id: 'selects', label: 'Pilih Data' },
    { id: 'statuses', label: 'Status' },
    { id: 'badges-avatars', label: 'Badge & Avatar' },
    { id: 'cards', label: 'Kartu & Statistik' },
    { id: 'table', label: 'Tabel' },
    { id: 'navigation', label: 'Navigasi' },
    { id: 'feedback', label: 'Feedback' },
    { id: 'overlay', label: 'Overlay' },
    { id: 'layout', label: 'Layout' },
];

/** Provinces as sample data for the client-side search: the list is short, so the browser filters it. */
const provinceOptions = [
    { value: 'aceh', label: 'Aceh', description: 'Sumatera' },
    { value: 'sumut', label: 'Sumatera Utara', description: 'Sumatera' },
    { value: 'sumbar', label: 'Sumatera Barat', description: 'Sumatera' },
    { value: 'riau', label: 'Riau', description: 'Sumatera' },
    { value: 'jakarta', label: 'DKI Jakarta', description: 'Jawa' },
    { value: 'jabar', label: 'Jawa Barat', description: 'Jawa' },
    { value: 'jateng', label: 'Jawa Tengah', description: 'Jawa' },
    { value: 'jatim', label: 'Jawa Timur', description: 'Jawa' },
    { value: 'bali', label: 'Bali', description: 'Bali dan Nusa Tenggara' },
    { value: 'sulsel', label: 'Sulawesi Selatan', description: 'Sulawesi' },
];

const statusGroups: Array<{ title: string; map: StatusMap; values: string[] }> = [
    { title: 'Status tiket', map: TICKET_STATUS, values: Object.keys(TICKET_STATUS) },
    { title: 'Status tagihan', map: INVOICE_STATUS, values: Object.keys(INVOICE_STATUS) },
    { title: 'Prioritas', map: PRIORITY, values: Object.keys(PRIORITY) },
];

function ToastDemo() {
    const { toast } = useToast();
    return (
        <Button
            onClick={() =>
                toast({
                    title: 'Berhasil',
                    description: 'Aksi tersimpan.',
                    variant: 'success',
                })
            }
        >
            Tampilkan toast
        </Button>
    );
}

/** What the picker handed to the form, shown as the server would receive it. */
function Sent({ label, value }: { label: string; value: string }) {
    return (
        <p role="status" aria-label={`Nilai ${label}`} className="text-sm text-muted-foreground">
            Nilai terkirim: <span className="font-medium text-foreground">{value || '-'}</span>
        </p>
    );
}

interface ComponentsProps extends Record<string, unknown> {
    /** Optional prop: the server sends it only when the select below searches (`demo_search`). */
    demoOptions?: RemoteOption[];
}

export default function Components({ demoOptions }: ComponentsProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [switchOn, setSwitchOn] = useState(false);
    const [tab, setTab] = useState('a');
    const [radio, setRadio] = useState('opt1');
    const [page, setPage] = useState(1);
    const [day, setDay] = useState('');
    const [dayTime, setDayTime] = useState('');
    const [month, setMonth] = useState('');
    const [time, setTime] = useState('');
    const [province, setProvince] = useState('');
    const [place, setPlace] = useState('');
    const [invoiceStatus, setInvoiceStatus] = useState('');

    return (
        <AdminLayout title="Komponen">
            <div className="mx-auto flex max-w-7xl gap-8 p-6 lg:p-8">
                <nav className="sticky top-8 space-y-1 hidden w-56 shrink-0 self-start lg:block">
                    {sections.map((s) => (
                        <a
                            key={s.id}
                            href={`#${s.id}`}
                            className="block rounded-lg px-3 py-2 text-sm text-surface-600 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-800"
                        >
                            {s.label}
                        </a>
                    ))}
                </nav>

                <div className="space-y-10 flex-1">
                    <PageHeader
                        title="Komponen"
                        subtitle="Semua komponen UI sistem desain inbils."
                        breadcrumbs={[{ label: 'Admin' }, { label: 'Komponen' }]}
                    />

                    <section id="buttons" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Tombol</CardTitle>
                                <CardDescription>Variasi tombol dan ikon aksi.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex flex-wrap gap-3">
                                    <Button>Utama</Button>
                                    <Button variant="secondary">Sekunder</Button>
                                    <Button variant="outline">Garis tepi</Button>
                                    <Button variant="ghost">Transparan</Button>
                                    <Button variant="destructive">Hapus</Button>
                                    <Button loading>Memuat</Button>
                                    <Button disabled>Nonaktif</Button>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Button size="sm">Kecil</Button>
                                    <Button size="md">Sedang</Button>
                                    <Button size="lg">Besar</Button>
                                </div>
                                <div className="flex items-center gap-3">
                                    <IconButton label="Aksi lainnya">
                                        <EllipsisVertical className="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <section id="inputs" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Input & Form</CardTitle>
                                <CardDescription>
                                    Kolom teks, area teks, pilihan, kotak centang, saklar, dan
                                    tombol radio.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input label="Teks" placeholder="Ketik..." />
                                    <Input
                                        label="Dengan ikon"
                                        placeholder="Cari..."
                                        leftIcon={<Search className="h-4 w-4" />}
                                    />
                                    <Input label="Galat" error="Wajib diisi." value="" />
                                    <Input label="Petunjuk" hint="Teks bantuan opsional." />
                                    <Textarea label="Area teks" placeholder="Catatan..." />
                                    <NativeSelect
                                        label="Pilihan"
                                        options={[
                                            { value: 'a', label: 'Opsi A' },
                                            { value: 'b', label: 'Opsi B' },
                                        ]}
                                    />
                                </div>
                                <div className="flex flex-col gap-4">
                                    <Checkbox
                                        label="Setujui syarat"
                                        description="Wajib untuk melanjutkan"
                                    />
                                    <Switch
                                        checked={switchOn}
                                        onCheckedChange={setSwitchOn}
                                        label="Aktifkan notifikasi"
                                    />
                                    <RadioGroup
                                        name="demo"
                                        value={radio}
                                        onChange={setRadio}
                                        label="Pilih satu"
                                    >
                                        <Radio
                                            value="opt1"
                                            label="Opsi 1"
                                            description="Pilihan pertama"
                                        />
                                        <Radio
                                            value="opt2"
                                            label="Opsi 2"
                                            description="Pilihan kedua"
                                        />
                                    </RadioGroup>
                                    <Label>Label mandiri</Label>
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <section id="dates" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Tanggal & Jam</CardTitle>
                                <CardDescription>
                                    Ketik dalam format tanggal perusahaan atau pilih dari popover.
                                    Server menerima ISO: <code>Y-m-d</code>, <code>Y-m-d H:i</code>,{' '}
                                    <code>Y-m</code> dan <code>H:i</code>.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-6 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <DatePicker
                                        label="Tanggal terbit"
                                        value={day}
                                        onChange={setDay}
                                    />
                                    <Sent label="Tanggal terbit" value={day} />
                                </div>
                                <div className="space-y-2">
                                    <DateTimePicker
                                        label="Jadwal kunjungan"
                                        value={dayTime}
                                        onChange={setDayTime}
                                    />
                                    <Sent label="Jadwal kunjungan" value={dayTime} />
                                </div>
                                <div className="space-y-2">
                                    <MonthPicker
                                        label="Periode"
                                        value={month}
                                        onChange={setMonth}
                                    />
                                    <Sent label="Periode" value={month} />
                                </div>
                                <div className="space-y-2">
                                    <TimeInput
                                        label="Jam tenang mulai"
                                        value={time}
                                        onChange={setTime}
                                    />
                                    <Sent label="Jam tenang mulai" value={time} />
                                </div>
                                <DatePicker
                                    label="Dengan galat"
                                    value=""
                                    onChange={() => undefined}
                                    error="Tanggal wajib diisi."
                                    required
                                />
                                <DatePicker
                                    label="Nonaktif"
                                    value="2026-10-13"
                                    onChange={() => undefined}
                                    disabled
                                />
                            </CardContent>
                        </Card>
                    </section>

                    <section id="selects" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Pilih Data</CardTitle>
                                <CardDescription>
                                    Daftar pendek disaring di browser; daftar yang tumbuh bersama
                                    bisnis dicari di server. Mengetik tidak mengubah pilihan.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-6 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <SearchSelect
                                        label="Provinsi"
                                        value={province}
                                        onChange={setProvince}
                                        options={provinceOptions}
                                        placeholder="Cari provinsi"
                                    />
                                    <Sent label="Provinsi" value={province} />
                                </div>
                                <div className="space-y-2">
                                    <RemoteSearchSelect
                                        label="Lokasi (cari di server)"
                                        field="demo"
                                        value={place}
                                        onChange={setPlace}
                                        options={demoOptions}
                                        placeholder="Cari nama atau kode lokasi"
                                        emptyText="Tidak ada lokasi yang cocok."
                                    />
                                    <Sent label="Lokasi (cari di server)" value={place} />
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <section id="statuses" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Status</CardTitle>
                                <CardDescription>
                                    Satu peta per domain di <code>lib/statuses</code>: nilai
                                    tersimpan menjadi label dan nada. Nilai yang belum dikenal
                                    tampil apa adanya, tidak merusak halaman.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                {statusGroups.map((group) => (
                                    <div key={group.title} className="space-y-2">
                                        <p className="text-sm font-medium">{group.title}</p>
                                        <div className="flex flex-wrap gap-2">
                                            {group.values.map((value) => (
                                                <EnumBadge
                                                    key={value}
                                                    map={group.map}
                                                    value={value}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                                <div className="space-y-2">
                                    <p className="text-sm font-medium">
                                        Boolean, tidak dikenal, kosong
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <EnumBadge map={ACTIVE_STATE} value />
                                        <EnumBadge map={ACTIVE_STATE} value={false} />
                                        <EnumBadge map={TICKET_STATUS} value="escalated" />
                                        <EnumBadge map={TICKET_STATUS} value={null} />
                                    </div>
                                </div>
                                <NativeSelect
                                    label="Filter status tagihan"
                                    value={invoiceStatus}
                                    onChange={(event) => setInvoiceStatus(event.target.value)}
                                    options={[
                                        { value: '', label: 'Semua status' },
                                        ...optionsOf(INVOICE_STATUS),
                                    ]}
                                />
                            </CardContent>
                        </Card>
                    </section>

                    <section id="badges-avatars" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Badge & Avatar</CardTitle>
                                <CardDescription>Label status dan avatar pengguna.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <Badge variant="neutral">Netral</Badge>
                                    <Badge variant="brand">Merek</Badge>
                                    <Badge variant="success">Berhasil</Badge>
                                    <Badge variant="warning">Peringatan</Badge>
                                    <Badge variant="danger">Bahaya</Badge>
                                    <Badge dot>Dengan titik</Badge>
                                </div>
                                <div className="flex items-center gap-4">
                                    <Avatar name="Pengguna A" size="sm" />
                                    <Avatar name="Pengguna B" size="md" status="online" />
                                    <Avatar name="Pengguna C" size="lg" />
                                    <Avatar src="" name="Cadangan" size="md" />
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <section id="cards" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Kartu & Statistik</CardTitle>
                                <CardDescription>Kartu konten dan kartu statistik.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Judul kartu</CardTitle>
                                            <CardDescription>
                                                Dengan semua subkomponen
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <p className="text-sm text-surface-600 dark:text-surface-400">
                                                Isi kartu.
                                            </p>
                                        </CardContent>
                                        <CardFooter>
                                            <Button variant="secondary" size="sm">
                                                Aksi
                                            </Button>
                                        </CardFooter>
                                    </Card>
                                    <StatCard
                                        label="Metrik contoh A"
                                        value="1.234"
                                        delta={12.5}
                                        deltaDirection="up"
                                    />
                                    <StatCard
                                        label="Metrik contoh B"
                                        value="567"
                                        delta={3.2}
                                        deltaDirection="down"
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <section id="table" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Tabel</CardTitle>
                                <CardDescription>Tabel data dengan aksi per baris.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Nama</TH>
                                            <TH>Email</TH>
                                            <TH>Status</TH>
                                            <TH>Aksi</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {(
                                            [
                                                ['Nama contoh A', 'active'],
                                                ['Nama contoh B', 'pending'],
                                                ['Nama contoh C', 'suspended'],
                                            ] as const
                                        ).map(([name, status]) => (
                                            <TR key={name}>
                                                <TD>{name}</TD>
                                                <TD>email@example.com</TD>
                                                <TD>
                                                    <EnumBadge
                                                        map={SUBSCRIPTION_STATUS}
                                                        value={status}
                                                    />
                                                </TD>
                                                <TD>
                                                    <Dropdown
                                                        trigger={
                                                            <IconButton label="Aksi lainnya">
                                                                <EllipsisVertical className="h-4 w-4" />
                                                            </IconButton>
                                                        }
                                                    >
                                                        <DropdownItem>Ubah</DropdownItem>
                                                        <DropdownItem>Lihat</DropdownItem>
                                                        <DropdownSeparator />
                                                        <DropdownItem destructive>
                                                            Hapus
                                                        </DropdownItem>
                                                    </Dropdown>
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                                <Pagination
                                    currentPage={page}
                                    lastPage={10}
                                    onPageChange={setPage}
                                />
                            </CardContent>
                        </Card>
                    </section>

                    <section id="navigation" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Navigasi</CardTitle>
                                <CardDescription>Breadcrumb, tab, paginasi.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <Breadcrumb items={[{ label: 'Admin' }, { label: 'Komponen' }]} />
                                <Tabs value={tab} onValueChange={setTab}>
                                    <TabList>
                                        <Tab value="a">Tab A</Tab>
                                        <Tab value="b">Tab B</Tab>
                                        <Tab value="c">Tab C</Tab>
                                    </TabList>
                                    <TabPanel value="a">
                                        <p className="text-sm text-surface-600 dark:text-surface-400">
                                            Isi tab A
                                        </p>
                                    </TabPanel>
                                    <TabPanel value="b">
                                        <p className="text-sm text-surface-600 dark:text-surface-400">
                                            Isi tab B
                                        </p>
                                    </TabPanel>
                                    <TabPanel value="c">
                                        <p className="text-sm text-surface-600 dark:text-surface-400">
                                            Isi tab C
                                        </p>
                                    </TabPanel>
                                </Tabs>
                                <Pagination currentPage={1} lastPage={5} onPageChange={() => {}} />
                            </CardContent>
                        </Card>
                    </section>

                    <section id="feedback" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Feedback</CardTitle>
                                <CardDescription>
                                    Alert, toast, spinner, skeleton, progress, status memuat, status
                                    kosong.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-3">
                                    <Alert variant="info" title="Informasi">
                                        Ini pesan informasi.
                                    </Alert>
                                    <Alert variant="success">Pesan berhasil.</Alert>
                                    <Alert
                                        variant="warning"
                                        title="Peringatan"
                                        onDismiss={() => {}}
                                    >
                                        Peringatan yang bisa ditutup.
                                    </Alert>
                                    <Alert variant="danger">Pesan bahaya.</Alert>
                                </div>
                                <ToastDemo />
                                <div className="flex items-center gap-4">
                                    <Spinner size="sm" />
                                    <Spinner size="md" />
                                    <Spinner size="lg" />
                                </div>
                                <div className="flex items-center gap-4">
                                    <Skeleton className="h-4 w-32" />
                                    <Skeleton className="h-4 w-48" />
                                    <Skeleton className="h-8 w-8 rounded-full" />
                                </div>
                                <Progress label="Mengunggah berkas" value={45} />
                                <LoadingState variant="rows" rows={2} />
                                <EmptyState
                                    icon={<Inbox className="h-12 w-12" />}
                                    title="Belum ada item"
                                    description="Belum ada item yang bisa ditampilkan."
                                    action={<Button variant="primary">Tambah item</Button>}
                                />
                            </CardContent>
                        </Card>
                    </section>

                    <section id="overlay" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Overlay</CardTitle>
                                <CardDescription>Modal, tooltip, menu.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex flex-wrap gap-3">
                                    <Button onClick={() => setModalOpen(true)}>Buka modal</Button>
                                    <Tooltip label="Teks tooltip">
                                        <Button variant="outline">Arahkan kursor</Button>
                                    </Tooltip>
                                    <Dropdown trigger={<Button variant="outline">Menu</Button>}>
                                        <DropdownItem>Ubah</DropdownItem>
                                        <DropdownItem>Duplikat</DropdownItem>
                                        <DropdownSeparator />
                                        <DropdownItem destructive>Hapus</DropdownItem>
                                    </Dropdown>
                                </div>
                                <Modal
                                    open={modalOpen}
                                    onClose={() => setModalOpen(false)}
                                    title="Contoh modal"
                                    size="md"
                                >
                                    <p className="text-sm text-surface-600 dark:text-surface-400">
                                        Ini isi modal.
                                    </p>
                                    <div className="mt-4 flex justify-end gap-2">
                                        <Button
                                            variant="secondary"
                                            onClick={() => setModalOpen(false)}
                                        >
                                            Batal
                                        </Button>
                                        <Button onClick={() => setModalOpen(false)}>
                                            Konfirmasi
                                        </Button>
                                    </div>
                                </Modal>
                            </CardContent>
                        </Card>
                    </section>

                    <section id="layout" className="scroll-mt-8">
                        <Card>
                            <CardHeader>
                                <CardTitle>Layout</CardTitle>
                                <CardDescription>
                                    Sidebar dan topbar (sedang dipakai di halaman ini via
                                    AdminLayout).
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-surface-600 dark:text-surface-400">
                                    Sidebar dan Topbar aktif di seluruh halaman admin. Lihat kiri
                                    dan atas.
                                </p>
                                <Badge variant="brand">AdminLayout</Badge>
                            </CardContent>
                        </Card>
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}
