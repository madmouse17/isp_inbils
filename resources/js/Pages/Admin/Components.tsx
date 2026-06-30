import { useState } from 'react';
import { MagnifyingGlassIcon, EllipsisVerticalIcon, InboxIcon } from '@heroicons/react/20/solid';
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
    Divider,
} from '@/Components/ui';

export default function Components() {
    const [modalOpen, setModalOpen] = useState(false);
    const [switchOn, setSwitchOn] = useState(false);
    const [tab, setTab] = useState('a');
    const [radio, setRadio] = useState('opt1');
    const [page, setPage] = useState(1);

    return (
        <AdminLayout title="UI Showcase">
            <div className="space-y-8">
                <Breadcrumb items={[{ label: 'Admin' }, { label: 'Components' }]} />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Buttons</h2>
                    <div className="flex flex-wrap gap-3">
                        <Button variant="primary">Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="danger">Danger</Button>
                        <Button variant="outline">Outline</Button>
                        <Button loading>Loading</Button>
                        <Button disabled disabled-tooltip>Disabled</Button>
                        <Button size="sm">Small</Button>
                        <Button size="lg">Large</Button>
                        <IconButton label="More">
                            <EllipsisVerticalIcon className="h-4 w-4" />
                        </IconButton>
                    </div>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Inputs</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label="Text" placeholder="Type..." />
                        <Input label="With icon" placeholder="Search..." leftIcon={<MagnifyingGlassIcon className="h-4 w-4" />} />
                        <Input label="Error" error="Required field" value="" />
                        <Input label="Hint" hint="Optional helper text" />
                        <Textarea label="Textarea" placeholder="Notes..." />
                        <Select label="Select" options={[{ value: 'a', label: 'Option A' }, { value: 'b', label: 'Option B' }]} />
                        <Checkbox label="Accept terms" description="Required to continue" />
                        <div className="flex flex-col gap-2">
                            <Switch checked={switchOn} onCheckedChange={setSwitchOn} label="Enable notifications" />
                            <RadioGroup name="demo" value={radio} onChange={setRadio} label="Choose one">
                                <Radio value="opt1" label="Option 1" description="First choice" />
                                <Radio value="opt2" label="Option 2" description="Second choice" />
                            </RadioGroup>
                        </div>
                    </div>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Badges & Avatars</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge variant="neutral">Neutral</Badge>
                        <Badge variant="brand">Brand</Badge>
                        <Badge variant="success">Success</Badge>
                        <Badge variant="warning">Warning</Badge>
                        <Badge variant="danger">Danger</Badge>
                        <Badge dot>With dot</Badge>
                    </div>
                    <div className="mt-4 flex items-center gap-4">
                        <Avatar name="John Doe" size="sm" />
                        <Avatar name="Jane Smith" size="md" status="online" />
                        <Avatar name="Bob Wilson" size="lg" />
                        <Avatar src="" name="No Image" size="md" />
                    </div>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Cards</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>Card Title</CardTitle>
                                <CardDescription>Some description here</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-surface-600 dark:text-surface-400">Card content goes here with whatever you need.</p>
                            </CardContent>
                            <CardFooter>
                                <Button variant="secondary" size="sm">Action</Button>
                            </CardFooter>
                        </Card>
                        <StatCard label="Revenue" value="$12,345" delta={12.5} deltaDirection="up" />
                        <StatCard label="Users" value="1,234" delta={3.2} deltaDirection="down" />
                    </div>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Table</h2>
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
                        </TBody>
                    </Table>
                    <div className="mt-4">
                        <Pagination currentPage={page} lastPage={10} onPageChange={setPage} />
                    </div>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Tabs</h2>
                    <Tabs value={tab} onValueChange={setTab}>
                        <TabList>
                            <Tab value="a">Tab A</Tab>
                            <Tab value="b">Tab B</Tab>
                            <Tab value="c">Tab C</Tab>
                        </TabList>
                        <TabPanel value="a">Content for Tab A</TabPanel>
                        <TabPanel value="b">Content for Tab B</TabPanel>
                        <TabPanel value="c">Content for Tab C</TabPanel>
                    </Tabs>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Alerts</h2>
                    <div className="space-y-3">
                        <Alert variant="info" title="Info">This is an info alert.</Alert>
                        <Alert variant="success">Success message here.</Alert>
                        <Alert variant="warning" title="Warning" onDismiss={() => {}}>Dismissible warning.</Alert>
                        <Alert variant="danger">Danger alert.</Alert>
                    </div>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Overlay</h2>
                    <div className="flex flex-wrap gap-3">
                        <Button onClick={() => setModalOpen(true)}>Open Modal</Button>
                        <Tooltip label="Tooltip text">
                            <Button variant="outline">Hover me</Button>
                        </Tooltip>
                    </div>
                    <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Example Modal" size="md">
                        <p className="text-sm text-surface-600 dark:text-surface-400">This is modal content.</p>
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                            <Button onClick={() => setModalOpen(false)}>Confirm</Button>
                        </div>
                    </Modal>
                </section>

                <Divider />

                <section>
                    <h2 className="mb-3 text-lg font-semibold text-surface-900 dark:text-surface-100">Misc</h2>
                    <div className="space-y-4">
                        <div className="flex items-center gap-4">
                            <Label>Label:</Label>
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
                    </div>
                </section>
            </div>
        </AdminLayout>
    );
}
