import { useForm, usePage } from '@inertiajs/react';
import { Alert, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PageProps } from '@/types';

/**
 * Where a flagged (legacy) customer login lands. The old password is switched off; the only way in is the
 * set-password link emailed to the customer, which proves they control the mailbox.
 */
export default function Password() {
    const { auth } = usePage<PageProps>().props;
    const { post, processing } = useForm({});

    return (
        <PortalLayout title="Atur kata sandi">
            <PageHeader
                title="Atur kata sandi"
                subtitle="Satu langkah lagi sebelum Anda membuka tagihan."
            />

            <Alert variant="warning">
                Akun Anda dibuat sebelum kata sandi acak dipakai, sehingga kata sandi lamanya
                mungkin masih berupa nomor telepon. Demi keamanan kata sandi lama dinonaktifkan.
                Buat kata sandi baru lewat link di email Anda.
            </Alert>

            <Card>
                <CardHeader>
                    <CardTitle>Link atur kata sandi</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <p className="text-sm">
                        Link dikirim ke <strong className="break-all">{auth.user?.email}</strong>.
                        Buka email itu, ikuti link-nya, lalu masuk dengan kata sandi baru.
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Belum menerima email, atau link sudah kedaluwarsa? Minta link baru. Periksa
                        juga folder spam.
                    </p>
                    <Button
                        type="button"
                        className="min-h-11"
                        loading={processing}
                        onClick={() =>
                            post(route('portal.password.link'), { preserveScroll: true })
                        }
                    >
                        Kirim link baru
                    </Button>
                </CardContent>
            </Card>
        </PortalLayout>
    );
}
