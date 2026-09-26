import { Compass } from 'lucide-react';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/Feedback';
import { useDocumentTitle } from '@/hooks/useUtils';

export default function NotFoundPage() {
  useDocumentTitle('Halaman tidak ditemukan');
  return (
    <Card>
      <EmptyState
        icon={<Compass className="size-5" />}
        title="Halaman tidak ditemukan"
        description="Alamat yang Anda buka tidak tersedia. Kembali ke dashboard untuk melanjutkan."
        action={<ButtonLink to="/" variant="primary">Ke Dashboard</ButtonLink>}
      />
    </Card>
  );
}
