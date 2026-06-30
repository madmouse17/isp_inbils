import { useState } from 'react';
import {
    MagnifyingGlassIcon,
    EllipsisVerticalIcon,
    InboxIcon,
} from '@heroicons/react/20/solid';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    IconButton,
    Input,
    Textarea,
    Select,
    Checkbox,
    Switch,
    RadioGroup,
    Radio,
    Label,
    Badge,
    Avatar,
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardContent,
    CardFooter,
    StatCard,
    Table,
    THead,
    TBody,
    TR,
    TH,
    TD,
    Breadcrumb,
    Pagination,
    Tabs,
    TabList,
    Tab,
    TabPanel,
    Modal,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    Tooltip,
    Alert,
    EmptyState,
    Spinner,
    Skeleton,
    useToast,
} from '@/Components/ui';

const sections = [
    { id: 'buttons', label: 'Tombol' },
    { id: 'inputs', label: 'Input & Form' },
    { id: 'badges-avatars', label: 'Badge & Avatar' },
    { id: 'cards', label: 'Kartu & Statistik' },
    { id: 'table', label: 'Tabel' },
    { id: 'navigation', label: 'Navigasi' },
    { id: 'feedback', label: 'Feedback' },
    { id: 'overlay', label: 'Overlay' },
    { id: 'layout', label: 'Layout' },
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
            Show Toast
        </Button>
    );
}

export default function Components() {
    const [modalOpen, setModalOpen] = useState(false);
    const [switchOn, setSwitchOn] = useState(false);
    const [tab, setTab] = useState('a');
    const [radio, setRadio] = useState('opt1');
    const [page, setPage] = useState(1);

    return (
        <AdminLayout title="Galeri Komponen">
            <div className="flex gap-8 p-6 lg:p-8 max-w-7xl mx-auto">
                <nav className="hidden lg:block w-56 shrink-0 sticky top-8 self-start space-y-1">
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

                <div className="flex-1 space-y-10">
                        <div className="space-y-4">
                            <Breadcrumb
                                items={[{ label: 'Admin' }, { label: 'Komponen' }]}
                            />
                            <div>
                                <h1 className="text-2xl font-semibold text-surface-900 dark:text-surface-100">
                                    Galeri Komponen
                                </h1>
                                <p className="text-sm text-surface-600 dark:text-surface-400">
                                    Semua komponen UI sistem desain inbils.
                                </p>
                            </div>
                        </div>

                        <section id="buttons" className="scroll-mt-8">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Tombol</CardTitle>
                                    <CardDescription>Variasi tombol dan ikon aksi.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex flex-wrap gap-3">
                                        <Button variant="primary">Primary</Button>
                                        <Button variant="secondary">Secondary</Button>
                                        <Button variant="ghost">Ghost</Button>
                                        <Button variant="danger">Danger</Button>
                                        <Button variant="outline">Outline</Button>
                                        <Button loading>Loading</Button>
                                        <Button disabled>Disabled</Button>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Button size="sm">Small</Button>
                                        <Button size="md">Medium</Button>
                                        <Button size="lg">Large</Button>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <IconButton label="More">
                                            <EllipsisVerticalIcon className="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </CardContent>
                            </Card>
                        </section>

                        <section id="inputs" className="scroll-mt-8">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Input & Form</CardTitle>
                                    <CardDescription>Kontrol input teks, area, select, checkbox, switch, radio.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Input label="Text" placeholder="Type..." />
                                        <Input
                                            label="With icon"
                                            placeholder="Search..."
                                            leftIcon={<MagnifyingGlassIcon className="h-4 w-4" />}
                                        />
                                        <Input label="Error" error="Required field" value="" />
                                        <Input label="Hint" hint="Optional helper text" />
                                        <Textarea label="Textarea" placeholder="Notes..." />
                                        <Select
                                            label="Select"
                                            options={[
                                                { value: 'a', label: 'Option A' },
                                                { value: 'b', label: 'Option B' },
                                            ]}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-4">
                                        <Checkbox label="Accept terms" description="Required to continue" />
                                        <Switch checked={switchOn} onCheckedChange={setSwitchOn} label="Enable notifications" />
                                        <RadioGroup name="demo" value={radio} onChange={setRadio} label="Choose one">
                                            <Radio value="opt1" label="Option 1" description="First choice" />
                                            <Radio value="opt2" label="Option 2" description="Second choice" />
                                        </RadioGroup>
                                        <Label>Standalone Label</Label>
                                    </div>
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
                                        <Badge variant="neutral">Neutral</Badge>
                                        <Badge variant="brand">Brand</Badge>
                                        <Badge variant="success">Success</Badge>
                                        <Badge variant="warning">Warning</Badge>
                                        <Badge variant="danger">Danger</Badge>
                                        <Badge dot>With dot</Badge>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <Avatar name="John Doe" size="sm" />
                                        <Avatar name="Jane Smith" size="md" status="online" />
                                        <Avatar name="Bob Wilson" size="lg" />
                                        <Avatar src="" name="Fallback" size="md" />
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
                                                <CardTitle>Card Title</CardTitle>
                                                <CardDescription>With all subcomponents</CardDescription>
                                            </CardHeader>
                                            <CardContent>
                                                <p className="text-sm text-surface-600 dark:text-surface-400">Card content with semantic copy.</p>
                                            </CardContent>
                                            <CardFooter>
                                                <Button variant="secondary" size="sm">Action</Button>
                                            </CardFooter>
                                        </Card>
                                        <StatCard label="Revenue" value="$12,345" delta={12.5} deltaDirection="up" />
                                        <StatCard label="Users" value="1,234" delta={3.2} deltaDirection="down" />
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
                                                <TH>Name</TH>
                                                <TH>Email</TH>
                                                <TH>Status</TH>
                                                <TH>Actions</TH>
                                            </TR>
                                        </THead>
                                        <TBody>
                                            <TR>
                                                <TD>John Doe</TD>
                                                <TD>john@example.com</TD>
                                                <TD><Badge variant="success">Active</Badge></TD>
                                                <TD>
                                                    <Dropdown trigger={<IconButton label="More"><EllipsisVerticalIcon className="h-4 w-4" /></IconButton>}>
                                                        <DropdownItem>Edit</DropdownItem>
                                                        <DropdownItem>View</DropdownItem>
                                                        <DropdownSeparator />
                                                        <DropdownItem destructive>Delete</DropdownItem>
                                                    </Dropdown>
                                                </TD>
                                            </TR>
                                            <TR>
                                                <TD>Jane Smith</TD>
                                                <TD>jane@example.com</TD>
                                                <TD><Badge variant="warning">Pending</Badge></TD>
                                                <TD>
                                                    <Dropdown trigger={<IconButton label="More"><EllipsisVerticalIcon className="h-4 w-4" /></IconButton>}>
                                                        <DropdownItem>Edit</DropdownItem>
                                                        <DropdownSeparator />
                                                        <DropdownItem destructive>Delete</DropdownItem>
                                                    </Dropdown>
                                                </TD>
                                            </TR>
                                            <TR>
                                                <TD>Bob Wilson</TD>
                                                <TD>bob@example.com</TD>
                                                <TD><Badge variant="danger">Banned</Badge></TD>
                                                <TD>
                                                    <Dropdown trigger={<IconButton label="More"><EllipsisVerticalIcon className="h-4 w-4" /></IconButton>}>
                                                        <DropdownItem>Edit</DropdownItem>
                                                        <DropdownSeparator />
                                                        <DropdownItem destructive>Delete</DropdownItem>
                                                    </Dropdown>
                                                </TD>
                                            </TR>
                                        </TBody>
                                    </Table>
                                    <Pagination currentPage={page} lastPage={10} onPageChange={setPage} />
                                </CardContent>
                            </Card>
                        </section>

                        <section id="navigation" className="scroll-mt-8">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Navigasi</CardTitle>
                                    <CardDescription>Breadcrumb, tabs, pagination.</CardDescription>
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
                                            <p className="text-sm text-surface-600 dark:text-surface-400">Content for Tab A</p>
                                        </TabPanel>
                                        <TabPanel value="b">
                                            <p className="text-sm text-surface-600 dark:text-surface-400">Content for Tab B</p>
                                        </TabPanel>
                                        <TabPanel value="c">
                                            <p className="text-sm text-surface-600 dark:text-surface-400">Content for Tab C</p>
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
                                    <CardDescription>Alert, toast, spinner, skeleton, empty state.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-3">
                                        <Alert variant="info" title="Info">This is an info alert.</Alert>
                                        <Alert variant="success">Success message here.</Alert>
                                        <Alert variant="warning" title="Warning" onDismiss={() => {}}>Dismissible warning.</Alert>
                                        <Alert variant="danger">Danger alert.</Alert>
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
                                    <EmptyState
                                        icon={<InboxIcon className="h-12 w-12" />}
                                        title="No items"
                                        description="There are no items to display yet."
                                        action={<Button variant="primary">Add item</Button>}
                                    />
                                </CardContent>
                            </Card>
                        </section>

                        <section id="overlay" className="scroll-mt-8">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Overlay</CardTitle>
                                    <CardDescription>Modal, tooltip, dropdown.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex flex-wrap gap-3">
                                        <Button onClick={() => setModalOpen(true)}>Open Modal</Button>
                                        <Tooltip label="Tooltip text">
                                            <Button variant="outline">Hover me</Button>
                                        </Tooltip>
                                        <Dropdown trigger={<Button variant="outline">Dropdown</Button>}>
                                            <DropdownItem>Edit</DropdownItem>
                                            <DropdownItem>Duplicate</DropdownItem>
                                            <DropdownSeparator />
                                            <DropdownItem destructive>Delete</DropdownItem>
                                        </Dropdown>
                                    </div>
                                    <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Example Modal" size="md">
                                        <p className="text-sm text-surface-600 dark:text-surface-400">This is modal content.</p>
                                        <div className="mt-4 flex justify-end gap-2">
                                            <Button variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                                            <Button onClick={() => setModalOpen(false)}>Confirm</Button>
                                        </div>
                                    </Modal>
                                </CardContent>
                            </Card>
                        </section>

                        <section id="layout" className="scroll-mt-8">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Layout</CardTitle>
                                    <CardDescription>Sidebar dan topbar (sedang dipakai di halaman ini via AdminLayout).</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <p className="text-sm text-surface-600 dark:text-surface-400">
                                        Sidebar dan Topbar aktif di seluruh halaman admin. Lihat kiri dan atas.
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
