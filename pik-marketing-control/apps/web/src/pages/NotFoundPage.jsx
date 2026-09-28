import { Compass } from 'lucide-react';
import { Button } from '../components/ui/Button.jsx';
import { EmptyState } from '../components/ui/States.jsx';

export function NotFoundPage() {
  return (
    <EmptyState
      icon={Compass}
      title="Halaman tidak ditemukan"
      text="Alamat yang Anda buka tidak tersedia atau sudah dipindahkan."
      action={<Button to="/dashboard">Ke Dashboard</Button>}
    />
  );
}
