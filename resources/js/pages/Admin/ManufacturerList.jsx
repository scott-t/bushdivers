import {
  Box,
  Button,
  Card,
  CardBody,
  Flex,
  Icon,
  Image,
  Table,
  TableContainer,
  Tbody,
  Td,
  Th,
  Thead,
  Tr,
} from '@chakra-ui/react'
import { Link, router } from '@inertiajs/react'
import { Pen, Trash2 } from 'lucide-react'
import React from 'react'

import AdminLayout from '../../components/layout/AdminLayout.jsx'

const ManufacturerList = ({ manufacturers }) => {
  const handleDelete = (id) => {
    const accept = window.confirm(
      'Are you sure you wish to delete this manufacturer?'
    )
    if (!accept) return

    router.get(`/admin/manufacturers/delete/${id}`)
  }

  return (
    <Card>
      <CardBody>
        <Flex justifyContent="right">
          <Link href="/admin/manufacturers/create">
            <Button>Add Manufacturer</Button>
          </Link>
        </Flex>
        <TableContainer>
          <Table>
            <Thead>
              <Tr>
                <Th>Logo</Th>
                <Th>Name</Th>
                <Th>Actions</Th>
              </Tr>
            </Thead>
            <Tbody>
              {manufacturers &&
                manufacturers.map((manufacturer) => (
                  <Tr key={manufacturer.id}>
                    <Td>
                      {manufacturer.logo_url && (
                        <Image
                          src={manufacturer.logo_url}
                          alt={manufacturer.name}
                          width={100}
                        />
                      )}
                    </Td>
                    <Td>{manufacturer.name}</Td>
                    <Td>
                      <Flex gap={2}>
                        <Link
                          href={`/admin/manufacturers/edit/${manufacturer.id}`}
                        >
                          <Box>
                            <Icon as={Pen} />
                          </Box>
                        </Link>
                        <Box
                          cursor="pointer"
                          onClick={() => handleDelete(manufacturer.id)}
                        >
                          <Icon as={Trash2} />
                        </Box>
                      </Flex>
                    </Td>
                  </Tr>
                ))}
            </Tbody>
          </Table>
        </TableContainer>
      </CardBody>
    </Card>
  )
}

ManufacturerList.layout = (page) => (
  <AdminLayout
    children={page}
    heading="Manufacturer Management"
    subHeading="Manage aircraft manufacturers"
  />
)

export default ManufacturerList
