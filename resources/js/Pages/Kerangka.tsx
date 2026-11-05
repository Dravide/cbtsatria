import PeranLayout from '@/Layouts/PeranLayout';

/**
 * Kerangka tiga peran non-Admin. routes/web.php mengirim peran + fase, jadi
 * satu komponen cukup sampai tiap peran mendapat tata letaknya sendiri.
 */
export default function Kerangka({ peran, fase }: { peran: string; fase: string }) {
    return <PeranLayout judul={`Konsol ${peran}`} peran={peran} fase={fase} />;
}
